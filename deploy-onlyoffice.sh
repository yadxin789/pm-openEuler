#!/usr/bin/env bash
# ============================================================================
#  PM 项目管理系统 - OnlyOffice Document Server 一键部署脚本
#
#  适用环境：openEuler 24.03 LTS 社区版（root 执行）
#  Web 服务器：openEuler 自带 Apache（httpd），无 Nginx；HTTPS 反代按 Apache 方式配置（文档 9.4）
#  前置条件：建议先按 deploy.sh 完成 PM 系统部署；若 PM 的 .env 不存在，
#            本脚本仍可独立完成 Document Server 部署（跳过 .env 联动配置）
#  功能（对应手动部署第 9 节）：
#    [1] 检查/安装并启动 Docker（已安装则跳过）
#    [2] 解析 JWT 密钥（优先复用 PM .env → 复用已有容器 → 自动生成）
#    [3] 创建并启动 onlyoffice/documentserver 容器（8080 端口、JWT 开启、
#        host.docker.internal 指向宿主机，供保存回调使用）
#    [4] 安装中文字体（已安装则跳过）并重启容器
#    [5] 防火墙放行 8080/tcp
#    [6] 配置 PM 系统 .env 三个 OnlyOffice 变量并刷新配置缓存
#    [7] 验证安装（healthcheck 返回 true）
#  可重复执行：脚本对每步做幂等处理，重复运行不会破坏已有部署。
# ============================================================================

set -euo pipefail

# ---------- 基础变量（按需修改） ----------
PM_DIR="/var/www/pm"                                  # PM 项目目录（存放 .env）
DS_CONTAINER="onlyoffice"                             # Document Server 容器名
DS_PORT=8080                                          # 宿主机映射端口（容器内固定为 80）
DS_IMAGE="onlyoffice/documentserver:latest"           # 镜像（社区版）
JWT_SECRET=""                                         # 留空=自动：优先复用 PM .env，其次复用已有容器，最后自动生成
DS_PUBLIC_URL=""                                      # 浏览器访问 DS 的地址；留空=自动生成 http://<服务器IP>:8080（配置了 HTTPS 反代时请填 https 地址）
PM_INTERNAL_URL=""                                    # DS 访问 PM 的地址；留空=保留 .env 已有值，无则默认 http://<宿主机IP>（与 PM 同机最稳）

# ---------- 输出辅助 ----------
log()  { echo -e "\033[1;34m[INFO]\033[0m $*"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $*"; }
err()  { echo -e "\033[1;31m[ERROR]\033[0m $*"; }
fail() { err "$*"; echo ""; err "部署失败。日志见上方；常见排查见部署文档 9.7 节。"; exit 1; }

# ---------- 前置检查 ----------
[ "$(id -u)" -eq 0 ] || fail "请使用 root 用户运行本脚本（sudo -i 或 su - 后执行）"

# 硬件要求提醒（文档 9.3：最低 2 vCPU / 2GB 内存 / 10GB 磁盘）
MEM_TOTAL_GB="$(awk '/MemTotal/ {printf "%.0f", $2/1024/1024}' /proc/meminfo 2>/dev/null)"
[ "${MEM_TOTAL_GB:-0}" -ge 2 ] || warn "当前内存 ${MEM_TOTAL_GB:-?}GB < 2GB，Document Server 可能无法正常运行（建议 ≥2GB，文档 9.3）"
DISK_AVAIL_GB="$(df -B1G / 2>/dev/null | awk 'NR==2 {print $4}' | tr -dc '0-9' || true)"
if [ -n "$DISK_AVAIL_GB" ] && [ "$DISK_AVAIL_GB" -lt 10 ] 2>/dev/null; then
    warn "根分区可用空间 ${DISK_AVAIL_GB}GB < 10GB（建议 ≥10GB，文档 9.3）"
fi

ENV_FILE="${PM_DIR}/.env"
log "========== OnlyOffice Document Server 一键部署开始 =========="

# =========================================================
# [1] 检查/安装并启动 Docker
# =========================================================
log "[1/7] 检查 Docker..."

if ! command -v docker >/dev/null 2>&1; then
    log "    未安装 Docker，正在安装..."
    dnf install -y docker >/dev/null 2>&1 || fail "Docker 安装失败，请检查软件源"
fi

# healthcheck 验证依赖 curl
command -v curl >/dev/null 2>&1 || dnf install -y curl >/dev/null 2>&1 || warn "curl 不可用，第 [7] 步 healthcheck 验证将无法执行（不影响部署本身）"

if ! systemctl is-active --quiet docker; then
    systemctl enable --now docker >/dev/null 2>&1 || fail "Docker 服务启动失败"
fi

# 等待 Docker daemon 就绪
for i in $(seq 1 15); do
    if docker info >/dev/null 2>&1; then break; fi
    sleep 1
done
docker info >/dev/null 2>&1 || fail "Docker daemon 未就绪，请检查 docker 服务状态"
log "    Docker 就绪: $(docker --version | awk '{print $3}' | tr -d ',')"

# =========================================================
# [2] 解析 JWT 密钥（.env → 已有容器 → 自动生成）
# =========================================================
log "[2/7] 解析 JWT 密钥..."

if [ -n "$JWT_SECRET" ]; then
    log "    使用脚本变量指定的 JWT_SECRET"
elif [ -f "$ENV_FILE" ] && grep -q '^ONLYOFFICE_JWT_SECRET=.\+' "$ENV_FILE"; then
    JWT_SECRET="$(grep '^ONLYOFFICE_JWT_SECRET=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
    log "    复用 PM .env 中的 ONLYOFFICE_JWT_SECRET（保证与 PM 侧一致）"
elif docker inspect "$DS_CONTAINER" >/dev/null 2>&1; then
    JWT_SECRET="$(docker inspect "$DS_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | sed -n 's/^JWT_SECRET=//p' | head -n1 || true)"
    [ -n "$JWT_SECRET" ] || fail "已有容器 ${DS_CONTAINER} 未设置 JWT_SECRET，请手动删除容器后重试"
    log "    复用已有容器 ${DS_CONTAINER} 的 JWT_SECRET"
else
    JWT_SECRET="$(openssl rand -hex 32)"
    log "    已自动生成 JWT_SECRET（64 位十六进制）"
fi
[ "${#JWT_SECRET}" -ge 16 ] || fail "JWT_SECRET 过短（<16 字符），请设置足够长的随机字符串"

# =========================================================
# [3] 创建并启动 Document Server 容器
# =========================================================
log "[3/7] 创建并启动 ${DS_CONTAINER} 容器..."

if docker inspect "$DS_CONTAINER" >/dev/null 2>&1; then
    CUR_JWT="$(docker inspect "$DS_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | sed -n 's/^JWT_SECRET=//p' | head -n1 || true)"
    if [ -n "$CUR_JWT" ] && [ "$CUR_JWT" = "$JWT_SECRET" ]; then
        log "    容器已存在且 JWT 一致，跳过创建"
        docker start "$DS_CONTAINER" >/dev/null 2>&1 || true
    else
        warn "    容器已存在但 JWT 与目标不一致，删除并重建（协同文档保存在 PM 侧 storage，不受影响）"
        docker rm -f "$DS_CONTAINER" >/dev/null 2>&1 || true
    fi
fi

if ! docker inspect "$DS_CONTAINER" >/dev/null 2>&1; then
    # 镜像不存在则先拉取（首次约数百 MB，视网络情况可能需要几分钟）
    if ! docker image inspect "$DS_IMAGE" >/dev/null 2>&1; then
        log "    正在拉取镜像 ${DS_IMAGE}（首次约数百 MB，请耐心等待）..."
        docker pull "$DS_IMAGE" || fail "镜像拉取失败，请检查网络（可更换镜像源后重试）"
    fi
    docker run -d --name "$DS_CONTAINER" \
        --restart=always \
        -p "${DS_PORT}:80" \
        --add-host host.docker.internal:host-gateway \
        -e JWT_ENABLED=true \
        -e JWT_SECRET="$JWT_SECRET" \
        "$DS_IMAGE" || fail "容器创建失败（端口 ${DS_PORT} 可能被占用，请先排查占用进程）"
    log "    容器已创建: ${DS_CONTAINER}（${DS_PORT} → 容器内 80）"
fi

# 等待容器进入 running 状态
for i in $(seq 1 30); do
    if [ "$(docker inspect -f '{{.State.Running}}' "$DS_CONTAINER" 2>/dev/null)" = "true" ]; then break; fi
    sleep 2
done
[ "$(docker inspect -f '{{.State.Running}}' "$DS_CONTAINER" 2>/dev/null)" = "true" ] || fail "容器未进入运行状态，请执行 docker logs ${DS_CONTAINER} 查看日志"

# =========================================================
# [4] 安装中文字体（防中文显示为方块）
# =========================================================
log "[4/7] 检查中文字体..."

if docker exec "$DS_CONTAINER" bash -c "command -v fc-list >/dev/null 2>&1 && fc-list 2>/dev/null | grep -qiE 'wqy|wenquanyi'" 2>/dev/null; then
    log "    已安装中文字体（WenQuanYi），跳过"
else
    log "    正在安装中文字体（fonts-wqy-zenhei / fonts-wqy-microhei）..."
    if docker exec "$DS_CONTAINER" bash -c "apt-get update >/dev/null 2>&1 && apt-get install -y fonts-wqy-zenhei fonts-wqy-microhei >/dev/null 2>&1 && rm -rf /var/lib/apt/lists/*"; then
        docker restart "$DS_CONTAINER" >/dev/null 2>&1 || true
        log "    中文字体安装完成，容器已重启"
    else
        warn "    中文字体安装失败（容器内网络问题？），中文可能显示为方块；可稍后手动执行文档 9.3 的命令并重启容器"
    fi
fi

# =========================================================
# [5] 防火墙放行 8080
# =========================================================
log "[5/7] 防火墙放行 ${DS_PORT}/tcp..."

if command -v firewall-cmd >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port="${DS_PORT}/tcp" >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
    log "    已放行 ${DS_PORT}/tcp"
else
    warn "    未检测到 firewall-cmd，跳过防火墙配置（请确认服务器防火墙/安全组已放行 ${DS_PORT} 端口）"
fi

# =========================================================
# [6] 配置 PM 系统 .env
# =========================================================
log "[6/7] 配置 PM 系统 .env..."

if [ ! -f "$ENV_FILE" ]; then
    warn "    未找到 ${ENV_FILE}（PM 尚未部署？），跳过 .env 联动配置；Document Server 已独立部署完成"
else
    SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [ -n "$SERVER_IP" ] || SERVER_IP="127.0.0.1"

    # ① ONLYOFFICE_SERVER_URL：浏览器访问 DS 的地址
    if [ -n "$DS_PUBLIC_URL" ]; then
        sed -i "s|^ONLYOFFICE_SERVER_URL=.*|ONLYOFFICE_SERVER_URL=${DS_PUBLIC_URL}|" "$ENV_FILE"
        grep -q '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" || echo "ONLYOFFICE_SERVER_URL=${DS_PUBLIC_URL}" >> "$ENV_FILE"
        log "    写入 ONLYOFFICE_SERVER_URL=${DS_PUBLIC_URL}（脚本变量指定）"
    elif grep -q '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" && ! grep -q '^ONLYOFFICE_SERVER_URL=http://localhost' "$ENV_FILE"; then
        log "    保留 .env 已有 ONLYOFFICE_SERVER_URL: $(grep '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" | cut -d= -f2-)"
    else
        sed -i "s|^ONLYOFFICE_SERVER_URL=.*|ONLYOFFICE_SERVER_URL=http://${SERVER_IP}:${DS_PORT}|" "$ENV_FILE"
        grep -q '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" || echo "ONLYOFFICE_SERVER_URL=http://${SERVER_IP}:${DS_PORT}" >> "$ENV_FILE"
        log "    写入 ONLYOFFICE_SERVER_URL=http://${SERVER_IP}:${DS_PORT}"
    fi

    # ② ONLYOFFICE_JWT_SECRET：必须与容器完全一致（否则打开编辑器报 JWT/401）
    sed -i "s|^ONLYOFFICE_JWT_SECRET=.*|ONLYOFFICE_JWT_SECRET=${JWT_SECRET}|" "$ENV_FILE"
    grep -q '^ONLYOFFICE_JWT_SECRET=' "$ENV_FILE" || echo "ONLYOFFICE_JWT_SECRET=${JWT_SECRET}" >> "$ENV_FILE"
    log "    写入 ONLYOFFICE_JWT_SECRET（与容器 JWT 一致）"

    # ③ ONLYOFFICE_PM_INTERNAL_URL：DS 服务器访问 PM 的地址
    if [ -n "$PM_INTERNAL_URL" ]; then
        sed -i "s|^ONLYOFFICE_PM_INTERNAL_URL=.*|ONLYOFFICE_PM_INTERNAL_URL=${PM_INTERNAL_URL}|" "$ENV_FILE"
        grep -q '^ONLYOFFICE_PM_INTERNAL_URL=' "$ENV_FILE" || echo "ONLYOFFICE_PM_INTERNAL_URL=${PM_INTERNAL_URL}" >> "$ENV_FILE"
        log "    写入 ONLYOFFICE_PM_INTERNAL_URL=${PM_INTERNAL_URL}（脚本变量指定）"
    elif grep -q '^ONLYOFFICE_PM_INTERNAL_URL=.\+' "$ENV_FILE"; then
        CUR_INTERNAL_URL="$(grep '^ONLYOFFICE_PM_INTERNAL_URL=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
        if echo "$CUR_INTERNAL_URL" | grep -qE '^[a-zA-Z][a-zA-Z0-9+.-]*://[^:/]+:[0-9]+'; then
            FIXED_INTERNAL_URL="$(echo "$CUR_INTERNAL_URL" | sed -E 's|^([a-zA-Z][a-zA-Z0-9+.-]*://[^:/]+):[0-9]+(.*)$|\1\2|')"
            warn "    .env 已有 ONLYOFFICE_PM_INTERNAL_URL=${CUR_INTERNAL_URL} 带端口，自动改为 ${FIXED_INTERNAL_URL}（本脚本以 Apache 80 端口部署 PM，无需带端口）"
            sed -i "s|^ONLYOFFICE_PM_INTERNAL_URL=.*|ONLYOFFICE_PM_INTERNAL_URL=${FIXED_INTERNAL_URL}|" "$ENV_FILE"
            log "    已修正 ONLYOFFICE_PM_INTERNAL_URL=${FIXED_INTERNAL_URL}"
        else
            log "    保留 .env 已有 ONLYOFFICE_PM_INTERNAL_URL: ${CUR_INTERNAL_URL}"
            warn "    请确认该地址在 Document Server 容器内可访问到 PM（PM 监听 80 端口时无需带端口）"
        fi
    else
        # 默认写宿主机 IP：DS 容器与 PM 同机时容器内可直接访问宿主机 IP，不依赖 host.docker.internal 映射
        echo "ONLYOFFICE_PM_INTERNAL_URL=http://${SERVER_IP}" >> "$ENV_FILE"
        log "    写入 ONLYOFFICE_PM_INTERNAL_URL=http://${SERVER_IP}"
    fi

    # 若已生成 Laravel 配置缓存则刷新（文档 9.5）
    if [ -f "${PM_DIR}/bootstrap/cache/config.php" ]; then
        ( cd "$PM_DIR" && php artisan config:cache >/dev/null 2>&1 ) || warn "    config:cache 刷新失败，可稍后手动执行: cd ${PM_DIR} && php artisan config:cache"
        log "    已刷新 Laravel 配置缓存"
    else
        log "    未发现配置缓存，无需刷新"
    fi
fi

# =========================================================
# [7] 验证安装（healthcheck）
# =========================================================
log "[7/7] 验证安装（healthcheck）..."

DS_OK=0
for i in $(seq 1 36); do   # 最多等 3 分钟（容器首次初始化较慢）
    if curl -sf "http://127.0.0.1:${DS_PORT}/healthcheck" 2>/dev/null | grep -q 'true'; then
        DS_OK=1
        break
    fi
    sleep 5
done

if [ "$DS_OK" = "1" ]; then
    log "    healthcheck 返回 true，Document Server 运行正常"
else
    warn "    healthcheck 未返回 true，容器可能仍在初始化或内存不足；请执行 docker logs ${DS_CONTAINER} 排查（文档 9.7）"
fi

# =========================================================
# 收尾
# =========================================================
echo ""
log "========== OnlyOffice Document Server 部署完成 =========="
echo ""
SERVER_IP_OUT="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -n "$SERVER_IP_OUT" ] || SERVER_IP_OUT="服务器IP"
echo "  访问地址   : http://${SERVER_IP_OUT}:${DS_PORT}（浏览器打开 /welcome 可看到欢迎页）"
echo "  容器名称   : ${DS_CONTAINER}（--restart=always，开机自启）"
echo "  JWT 密钥   : ${JWT_SECRET}"
echo "  常用命令   :"
echo "      docker logs ${DS_CONTAINER}        # 查看容器日志"
echo "      docker restart ${DS_CONTAINER}     # 重启容器"
echo ""
if [ -f "$ENV_FILE" ]; then
    echo "  PM .env 配置:"
    grep -E '^ONLYOFFICE_' "$ENV_FILE" | sed 's/^/      /' || true
fi
echo ""
warn "提示：公网部署建议给 Document Server 配置 HTTPS 反向代理（Apache 443 → 127.0.0.1:${DS_PORT}，见文档 9.4），并将 ONLYOFFICE_SERVER_URL 改为 https 地址"
warn "      Apache 反代参考（需 dnf install -y mod_ssl，并启用 proxy_http 模块）："
warn "          <VirtualHost *:443>"
warn "              SSLEngine on"
warn "              SSLCertificateFile /etc/pki/tls/certs/office.crt"
warn "              SSLCertificateKeyFile /etc/pki/tls/private/office.key"
warn "              ProxyPass / http://127.0.0.1:${DS_PORT}/"
warn "              ProxyPassReverse / http://127.0.0.1:${DS_PORT}/"
warn "          </VirtualHost>"
warn "      内网 HTTP 部署时无需反代，保持 PM 与 DS 均 HTTP 即可（文档 9.4 方式一）"
warn "提示：Document Server 社区版最多支持 20 个同时编辑连接（文档 9.7）；协同文档本体保存在 PM 侧 storage/app/file-repository/collab/，请纳入备份范围（文档 9.6）"
