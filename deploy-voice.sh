#!/usr/bin/env bash
# ============================================================================
#  PM 电话会议一键部署脚本（deploy-voice.sh）
#
#  环境：openEuler 24.03 LTS，root 执行，幂等可重复运行。
#  前置：deploy.sh 已跑过（PM 主系统、Apache、php-fpm、Docker 已就绪）。
#
#  功能：
#    [1] 安装 coturn
#    [2] 生成自签名 SSL（含 SAN=服务器 IP）
#    [3] Docker 起 Janus Gateway（AudioBridge 插件，仅本机信令）
#    [4] 写 coturn 配置（内网 STUN）
#    [5] Apache 反代 /janus-ws 到 Janus 8188
#    [6] 安装 systemd units：pm-signal / pm-janus / pm-coturn
#    [7] firewalld 放行端口
#    [8] 拷 janus.js 到 public/vendor/janus/
#    [9] php artisan migrate
# ============================================================================

set -euo pipefail

PM_DIR="/var/www/pm"
APP_USER="pmadmin"
VOICE_SSL_DIR="/etc/pm/voice/ssl"
JANUS_HTTP_PORT=8088
JANUS_WS_PORT=8188
JANUS_ADMIN_PORT=7088
JANUS_RTP_RANGE="20000-40000"

log()  { echo -e "\033[1;34m[INFO]\033[0m $*"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $*"; }
err()  { echo -e "\033[1;31m[ERROR]\033[0m $*"; }

[ "$(id -u)" -eq 0 ] || { err "请用 root 运行"; exit 1; }
[ -d "$PM_DIR" ] || { err "$PM_DIR 不存在，请先跑 deploy.sh"; exit 1; }

# 服务器内网 IP（用于 SAN 和提示）
SERVER_IP=$(hostname -I | awk '{print $1}')
log "========== PM 电话会议部署开始（服务器 IP: $SERVER_IP）=========="

# ============================================================
# [1] 安装 coturn
# ============================================================
log "[1/9] 安装 coturn..."
dnf install -y coturn >/dev/null 2>&1 || warn "coturn 包未找到，请手动编译"

# ============================================================
# [2] 自签名 SSL 证书
# ============================================================
log "[2/9] 生成自签名 SSL..."
mkdir -p "$VOICE_SSL_DIR"
if [ ! -f "$VOICE_SSL_DIR/server.crt" ]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$VOICE_SSL_DIR/server.key" \
        -out "$VOICE_SSL_DIR/server.crt" \
        -subj "/CN=$SERVER_IP" \
        -addext "subjectAltName=IP:$SERVER_IP,DNS:localhost" >/dev/null 2>&1
    chown -R "$APP_USER:$APP_USER" "$VOICE_SSL_DIR"
fi

# ============================================================
# [3] Docker 起 Janus
# ============================================================
log "[3/9] 启动 Janus Gateway 容器..."
if ! docker ps --format '{{.Names}}' | grep -q '^pm-janus$'; then
    docker run -d \
        --name pm-janus \
        --restart=always \
        -p 127.0.0.1:$JANUS_HTTP_PORT:8088 \
        -p 127.0.0.1:$JANUS_WS_PORT:8188 \
        -p 127.0.0.1:$JANUS_ADMIN_PORT:7088 \
        -p $JANUS_RTP_RANGE/udp:$JANUS_RTP_RANGE/udp \
        meetecho/janus-gateway:latest >/dev/null
    log "Janus 容器已启动"
else
    log "Janus 容器已存在，跳过"
fi

# ============================================================
# [4] coturn 配置
# ============================================================
log "[4/9] 写 coturn 配置..."
TURN_SECRET=$(grep -E '^TURN_SECRET=' "$PM_DIR/.env" | cut -d= -f2-)
[ -z "$TURN_SECRET" ] && TURN_SECRET=$(openssl rand -hex 16) && echo "TURN_SECRET=$TURN_SECRET" >> "$PM_DIR/.env"

cat > /etc/coturn/turnserver.conf <<EOF
listening-port=3478
listening-ip=0.0.0.0
external-ip=$SERVER_IP
min-port=49152
max-port=65535
fingerprint
lt-cred-mech
realm=pm-voice
user=pm:$TURN_SECRET
log-file=/var/log/turnserver.log
EOF
chown -R "$APP_USER:$APP_USER" /etc/coturn/

systemctl enable --now coturn >/dev/null 2>&1 || systemctl enable --now turnserver >/dev/null 2>&1 || warn "coturn 服务启动失败，请手动检查"

# ============================================================
# [5] Apache 反代 /janus-ws 到 Janus
# ============================================================
log "[5/9] 配置 Apache 反代..."
if ! grep -q "janus-ws" /etc/httpd/conf.d/pm.conf 2>/dev/null; then
    cat >> /etc/httpd/conf.d/pm.conf <<EOF

# Janus WebSocket 反代
ProxyPass "/janus-ws" "ws://127.0.0.1:$JANUS_WS_PORT"
ProxyPassReverse "/janus-ws" "ws://127.0.0.1:$JANUS_WS_PORT"
ProxyPreserveHost On
EOF
    systemctl reload httpd
fi

# ============================================================
# [6] systemd units
# ============================================================
log "[6/9] 安装 systemd units..."
# pm-signal
cp "$PM_DIR/server/pm-signal.service" /etc/systemd/system/pm-signal.service
# pm-janus 已由 Docker restart=always 管理，无需额外 unit
# pm-coturn 已由 systemctl enable coturn 管理

# 写 .env 补充
grep -q '^VOICE_SIGNAL_WS=' "$PM_DIR/.env" || cat >> "$PM_DIR/.env" <<EOF
VOICE_SIGNAL_WS=wss://$SERVER_IP:3001
VOICE_JANUS_WS=ws://127.0.0.1:8188
VOICE_JANUS_ADMIN=http://127.0.0.1:7088
VOICE_STUN=stun:$SERVER_IP:3478
VOICE_PM_INTERNAL_URL=http://127.0.0.1
EOF

systemctl daemon-reload
systemctl enable --now pm-signal

# ============================================================
# [7] 防火墙
# ============================================================
log "[7/9] firewalld 放行..."
firewall-cmd --permanent --add-port=3001/tcp >/dev/null 2>&1 || true
firewall-cmd --permanent --add-port=3478/udp >/dev/null 2>&1 || true
firewall-cmd --permanent --add-port=3478/tcp >/dev/null 2>&1 || true
firewall-cmd --permanent --add-port=$JANUS_RTP_RANGE/udp >/dev/null 2>&1 || true
firewall-cmd --reload >/dev/null 2>&1 || true

# ============================================================
# [8] janus.js 静态资源
# ============================================================
log "[8/9] 拷 janus.js 到 public..."
mkdir -p "$PM_DIR/public/vendor/janus"
if [ ! -f "$PM_DIR/public/vendor/janus/janus.js" ]; then
    docker cp pm-janus:/opt/janus/share/janus/demos/janus.js "$PM_DIR/public/vendor/janus/janus.js" 2>/dev/null \
        || warn "janus.js 拷贝失败，请从 https://janus.conf.meetecho.com/ 手动下载到 public/vendor/janus/"
fi
chown -R "$APP_USER:$APP_USER" "$PM_DIR/public/vendor/"

# ============================================================
# [9] migrate
# ============================================================
log "[9/9] 跑迁移..."
cd "$PM_DIR"
sudo -u "$APP_USER" php artisan migrate --force

# ============================================================
# 健康检查
# ============================================================
log "========== 健康检查 =========="
curl -s http://127.0.0.1:$JANUS_ADMIN_PORT/info >/dev/null && log "Janus admin OK" || warn "Janus admin 未响应"
ss -lunup | grep -q ':3478' && log "coturn 3478/udp OK" || warn "coturn 未监听"
ss -ltnp | grep -q ':3001' && log "pm-signal 3001 OK" || warn "pm-signal 未监听"

cat <<EOF

========== 部署完成 ==========
访问：https://$SERVER_IP/project/<项目ID>/voice
注意：浏览器需手动信任自签名证书（首次访问点"高级→继续"）。
日志：journalctl -u pm-signal -f；/var/log/pm-signal.log
EOF
