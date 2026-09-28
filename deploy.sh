#!/usr/bin/env bash
# ============================================================================
#  PM 项目管理系统 - openEuler Apache 一键部署脚本 (deploy.sh)
#
#  适用环境：openEuler 24.03 LTS 社区版（root 执行）
#  前置条件：
#    1. 存储 PM 项目 ZIP（如 pm.zip）的 U 盘已插入【服务器本地 USB 口】
#       （也可用参数直接指定 ZIP 路径：./deploy.sh /path/to/pm.zip）
#    2. ZIP 解压后的根目录应直接包含 composer.json、app/、public/（脚本会自动修正多套一层的情况）
#  功能（对应手动部署第 3~9 节）：
#    [1] 安装 PHP 8.3 + 扩展 / httpd / php-fpm / MySQL / Composer / unzip
#    [2] 查找并挂载 U 盘，解压项目代码到 /var/www/pm
#    [3] 启动 MySQL，创建数据库 pm_db（root 密码 root）
#    [4] 生成 .env 与 APP_KEY
#    [5] composer install --no-dev
#    [6] php artisan migrate + db:seed（预置数据，仅在空库时执行）
#    [7] storage:link + 前端 npm run build（已构建则自动跳过）
#    [8] 配置 PHP-FPM / Apache(httpd) / SELinux / 防火墙并启动
#    [9] 安装并启动 crond，写入 crontab 定时调度（schedule:run，归属应用用户）
#    [10] 创建并启动队列 systemd 服务 pm-queue
#    [11] 部署 OnlyOffice Document Server（Docker 容器，文档第 9 节；默认启用，
#         设 INSTALL_ONLYOFFICE=0 可跳过，仅部署 PM 主系统）
#  可重复执行：脚本对每步做幂等处理，重复运行不会破坏已有部署。
# ============================================================================

set -euo pipefail

# ---------- 基础变量（按需修改） ----------
PM_DIR="/var/www/pm"
DB_NAME="pm_db"
DB_USER="root"
DB_PASS="root"
APP_USER="pmadmin"  # PHP-FPM 运行用户（openEuler 默认无 nginx 用户）
FPM_SOCK="/run/php-fpm/www.sock"
ZIP_PATH="/mnt/usb/pm-server-openEuler.zip"                       # 可选：直接指定 ZIP 绝对路径
ZIP_NAME_PATTERN="pm-server-openEuler.zip"              # 自动查找 U 盘时的文件名匹配

# ---------- OnlyOffice Document Server（可选组件，文档第 9 节） ----------
INSTALL_ONLYOFFICE=1          # 设为 0 可跳过 OnlyOffice 部署（仅部署 PM 主系统）
DS_CONTAINER="onlyoffice"     # Document Server 容器名
DS_PORT=8080                  # 宿主机映射端口（容器内固定为 80）
DS_IMAGE="onlyoffice/documentserver:latest"   # 镜像（社区版）
JWT_SECRET=""                 # 留空=自动：优先复用 .env，其次复用已有容器，最后自动生成
DS_PUBLIC_URL=""              # 浏览器访问 DS 的地址；留空=自动生成 http://<服务器IP>:8080（配置了 HTTPS 反代时请填 https 地址）
PM_INTERNAL_URL=""            # DS 访问 PM 的地址；留空=保留 .env 已有值，无则默认 http://<宿主机IP>（与 PM 同机最稳）
ENV_FILE="${PM_DIR}/.env"

# ---------- 输出辅助 ----------
log()  { echo -e "\033[1;34m[INFO]\033[0m $*"; }
warn() { echo -e "\033[1;33m[WARN]\033[0m $*"; }
err()  { echo -e "\033[1;31m[ERROR]\033[0m $*"; }

fail() { err "$*"; echo ""; err "部署失败。日志输出见上方；常见排查见部署文档第十三节（OnlyOffice 相关见 9.7 节）。"; exit 1; }

# ---------- 前置检查 ----------
[ "$(id -u)" -eq 0 ] || fail "请使用 root 用户运行本脚本（sudo -i 或 su - 后执行）"

log "========== PM 一键部署开始 =========="

# =========================================================
# [1] 安装系统依赖
# =========================================================
log "[1/11] 检查并安装系统依赖..."

dnf install -y php php-cli php-fpm php-common php-mysqlnd \
    php-mbstring php-xml php-gd php-zip php-intl php-bcmath \
    php-curl php-openssl php-process \
    httpd unzip curl >/dev/null 2>&1 || dnf install -y php php-cli php-fpm php-common php-mysqlnd \
    php-mbstring php-xml php-gd php-zip php-intl php-bcmath \
    php-curl php-openssl php-process \
    httpd unzip curl

# MySQL 或 MariaDB（openEuler 仓库可能为 mariadb 别名）
#if ! rpm -q mysql-server mariadb-server >/dev/null 2>&1; then
    #dnf install -y mysql-server mysql >/dev/null 2>&1 || \
    #dnf install -y mariadb-server mariadb || \
    #fail "MySQL/MariaDB 安装失败，请检查软件源"
#fi

# 确定数据库服务名
#if systemctl list-unit-files --no-legend 2>/dev/null | grep -q '^mysqld.service'; then
    #MYSQL_SVC="mysqld"
#elif systemctl list-unit-files --no-legend 2>/dev/null | grep -q '^mariadb.service'; then
    MYSQL_SVC="mariadb"
#else
    #MYSQL_SVC="mysqld"
#fi
log "    数据库服务名: ${MYSQL_SVC}"

# Composer
#if ! command -v composer >/dev/null 2>&1; then
   # php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
   # php composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
   # rm -f composer-setup.php
#fi

# PHP 版本校验
#PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
#[ "$PHP_VER" = "8.3" ] || warn "当前 PHP 版本 ${PHP_VER}，文档要求 8.3.x（若为 8.4 需降级）"

# =========================================================
# [2] 获取项目代码（U 盘挂载 / 指定路径）
# =========================================================
log "[2/11] 获取 PM 项目代码..."

if [ -f "${PM_DIR}/composer.json" ]; then
    log "    /var/www/pm 已存在项目代码，跳过解压（如需强制覆盖请先删除该目录）"
else
    WORK_ZIP=""
    if [ -n "$ZIP_PATH" ]; then
        [ -f "$ZIP_PATH" ] || fail "指定的 ZIP 不存在: $ZIP_PATH"
        WORK_ZIP="$ZIP_PATH"
    else
        # 1) 查找已挂载介质中的 pm*.zip
        WORK_ZIP="$(find /mnt /media /run/media -maxdepth 4 -type f -iname "$ZIP_NAME_PATTERN" 2>/dev/null | head -n1 || true)"
        # 2) 未找到则尝试挂载服务器本地 U 盘
        if [ -z "$WORK_ZIP" ]; then
            log "    未发现已挂载的 ZIP，尝试挂载服务器本地 U 盘..."
            USB_MNT="/mnt/usb"
            for dev in $(lsblk -rno NAME,MOUNTPOINT 2>/dev/null | awk '$2 == "" && $1 ~ /^sd[a-z][0-9]*$/ {print $1}'); do
                mkdir -p "$USB_MNT"
                if mount "/dev/${dev}" "$USB_MNT" >/dev/null 2>&1 || \
                   mount -t ntfs-3g "/dev/${dev}" "$USB_MNT" >/dev/null 2>&1; then
                    WORK_ZIP="$(find "$USB_MNT" -maxdepth 2 -type f -iname "$ZIP_NAME_PATTERN" 2>/dev/null | head -n1 || true)"
                    if [ -n "$WORK_ZIP" ]; then
                        log "    已从 U 盘找到: $WORK_ZIP"
                        break
                    fi
                    umount "$USB_MNT" >/dev/null 2>&1 || true
                fi
            done
            [ -n "$WORK_ZIP" ] || fail "未找到 ${ZIP_NAME_PATTERN}。请确认 U 盘已插入服务器本地 USB 口，或用 ./deploy.sh /绝对路径/pm.zip 指定"
        fi
    fi

    EXTRACT_DIR="$(mktemp -d /tmp/pm_extract.XXXXXX)"
    log "    解压: $WORK_ZIP"
    unzip -q "$WORK_ZIP" -d "$EXTRACT_DIR" || fail "ZIP 解压失败（文件可能损坏，或需 dnf install -y unzip）"

    # 修正多套一层目录：若解压后顶层只有一个目录且其内含 composer.json
    if [ ! -f "$EXTRACT_DIR/composer.json" ]; then
        INNER="$(find "$EXTRACT_DIR" -maxdepth 2 -name composer.json -print -quit 2>/dev/null || true)"
        if [ -n "$INNER" ]; then
            INNER_DIR="$(dirname "$INNER")"
            log "    检测到压缩包多套一层目录，自动修正: $INNER_DIR"
            EXTRACT_DIR="$INNER_DIR"
        else
            fail "ZIP 中未找到 composer.json，请确认上传的是 PM 项目压缩包"
        fi
    fi

    mkdir -p "$PM_DIR"
    cp -a "$EXTRACT_DIR/." "$PM_DIR/"
    rm -rf "$(dirname "$EXTRACT_DIR")" 2>/dev/null || true

    # U 盘卸载提示
    if [ -n "${USB_MNT:-}" ] && mountpoint -q "$USB_MNT" 2>/dev/null; then
        umount "$USB_MNT" >/dev/null 2>&1 && log "    U 盘已安全卸载，可拔出" || warn "    U 盘仍在占用，稍后手动 umount $USB_MNT"
    fi
fi

cd "$PM_DIR"

# =========================================================
# [3] 启动数据库并创建 pm_db
# =========================================================
log "[3/11] 启动数据库并创建 ${DB_NAME}..."

systemctl enable --now "$MYSQL_SVC" >/dev/null 2>&1 || fail "数据库服务启动失败"

# 等待数据库就绪
for i in $(seq 1 15); do
    if mysqladmin -uroot ping >/dev/null 2>&1; then break; fi
    sleep 1
done

if mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
    mysql -uroot <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
FLUSH PRIVILEGES;
SQL
elif mysql -uroot -p"${DB_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
    mysql -uroot -p"${DB_PASS}" -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
else
    fail "无法连接 MySQL root（既不是空密码也不是密码 ${DB_PASS}），请手动修正数据库"
fi
log "    数据库 ${DB_NAME} 就绪"

# =========================================================
# [4] 配置 .env 与 APP_KEY
# =========================================================
log "[4/11] 配置 .env 与 APP_KEY..."

if [ ! -f ".env" ]; then
    cp .env.example .env
    log "    已从 .env.example 生成 .env"
fi

# 确保数据库配置为目标值（幂等）
sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=mysql/" .env
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
sed -i "s/^DB_USERNAME=.*/DB_USERNAME=${DB_USER}/" .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env

# 生产参数（仅在未设置时补充）
grep -q '^APP_ENV=' .env || echo "APP_ENV=production" >> .env
grep -q '^APP_DEBUG=' .env || echo "APP_DEBUG=false" >> .env

# 生成 APP_KEY（仅当为空时）
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
    log "    APP_KEY 已生成"
fi

# =========================================================
# [5] 安装 PHP 依赖
# =========================================================
log "[5/11] composer install --no-dev --optimize-autoloader..."
#composer install --no-dev --optimize-autoloader --no-interaction || fail "composer install 失败，请检查网络与 composer.json"

# =========================================================
# [6] 数据库迁移与预置数据（幂等）
# =========================================================
log "[6/11] 执行数据库迁移与预置数据..."

php artisan migrate --force || fail "数据库迁移失败（确认含 2026_09_18_000000_add_foreign_keys_for_mysql.php 等迁移）"

USER_COUNT="$(mysql -uroot -p"${DB_PASS}" -N -e "SELECT COUNT(*) FROM ${DB_NAME}.users;" 2>/dev/null || echo 0)"
if [ "${USER_COUNT:-0}" = "0" ]; then
    php artisan db:seed --force || fail "预置数据填充失败"
    log "    预置数据已写入（角色/部门/管理员账号等）"
else
    log "    users 表已有 ${USER_COUNT} 条数据，跳过预置数据（避免重复）"
fi

# =========================================================
# [7] storage 链接与前端构建（已构建则跳过）
# =========================================================
log "[7/11] storage:link 与前端构建..."

[ -L "public/storage" ] || php artisan storage:link

if [ -f "public/build/manifest.json" ]; then
    log "    检测到 public/build 已构建，跳过 npm 构建"
else
    if command -v node >/dev/null 2>&1 && [ "$(node -e 'console.log(Number(process.versions.node.split(".")[0])>=18)' 2>/dev/null)" = "true" ]; then
        npm install --no-audit --no-fund >/dev/null 2>&1 || true
        if ! npm run build; then
            warn "    npm run build 失败，页面可访问但样式/资源可能缺失，请手动重试"
        else
            log "    前端资源构建完成"
        fi
    else
        warn "    未找到 Node.js ≥18，跳过前端构建（页面可访问但资源可能缺失）"
    fi
fi

# =========================================================
# [8] PHP-FPM / Apache / SELinux / 防火墙 配置并启动
# =========================================================
log "[8/11] 配置 PHP-FPM 与 Apache(httpd)..."

# 创建 PHP-FPM 运行用户（openEuler 可能没有 nginx 用户）
id -u "${APP_USER}" >/dev/null 2>&1 || useradd -r -s /sbin/nologin "${APP_USER}"

# www.conf：确保 user/group/listen
PHPFPM_CONF="/etc/php-fpm.d/www.conf"
sed -i "s/^user = .*/user = ${APP_USER}/" "$PHPFPM_CONF"
sed -i "s/^group = .*/group = ${APP_USER}/" "$PHPFPM_CONF"
sed -i "s|^listen = .*|listen = ${FPM_SOCK}|" "$PHPFPM_CONF"
grep -q "^user = " "$PHPFPM_CONF" || echo "user = ${APP_USER}" >> "$PHPFPM_CONF"
grep -q "^group = " "$PHPFPM_CONF" || echo "group = ${APP_USER}" >> "$PHPFPM_CONF"
grep -q "^listen = " "$PHPFPM_CONF" || echo "listen = ${FPM_SOCK}" >> "$PHPFPM_CONF"

# php.ini 上传与执行参数
PHP_INI="/etc/php.ini"
sed -i "s/^upload_max_filesize = .*/upload_max_filesize = 25M/" "$PHP_INI"
sed -i "s/^post_max_size = .*/post_max_size = 26M/" "$PHP_INI"
sed -i "s/^memory_limit = .*/memory_limit = 256M/" "$PHP_INI"
sed -i "s/^max_execution_time = .*/max_execution_time = 120/" "$PHP_INI"
grep -q "^upload_max_filesize = 25M" "$PHP_INI" || echo "upload_max_filesize = 25M" >> "$PHP_INI"
grep -q "^post_max_size = 26M" "$PHP_INI" || echo "post_max_size = 26M" >> "$PHP_INI"
grep -q "^memory_limit = 256M" "$PHP_INI" || echo "memory_limit = 256M" >> "$PHP_INI"
grep -q "^max_execution_time = 120" "$PHP_INI" || echo "max_execution_time = 120" >> "$PHP_INI"

# 目录权限（php-fpm 用户可写）
chown -R "${APP_USER}:${APP_USER}" "$PM_DIR"
chmod -R 775 "$PM_DIR/storage" "$PM_DIR/bootstrap/cache"

# Apache VirtualHost（幂等：直接覆盖为脚本标准配置）
cat > /etc/httpd/conf.d/pm.conf <<APACHE
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot ${PM_DIR}/public

    <Directory ${PM_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \\.php\$>
        SetHandler "proxy:unix:${FPM_SOCK}|fcgi://localhost"
    </FilesMatch>

    LimitRequestBody 26214400

    ErrorLog logs/pm-error.log
    CustomLog logs/pm-access.log combined
</VirtualHost>
APACHE

# 检查 Apache 配置
if ! apachectl configtest >/dev/null 2>&1; then
    err "Apache 配置校验失败，详细错误如下："
    apachectl configtest 2>&1 | while read -r line; do err "    $line"; done
    err "请检查 /etc/httpd/conf.d/pm.conf"
    fail "Apache 配置校验失败"
fi

# SELinux（openEuler 默认 Enforcing）
if command -v semanage >/dev/null 2>&1 || dnf install -y policycoreutils-python-utils >/dev/null 2>&1; then
    semanage fcontext -a -t httpd_sys_content_t "${PM_DIR}(/.*)?" 2>/dev/null || true
    semanage fcontext -a -t httpd_sys_rw_content_t "${PM_DIR}/storage(/.*)?" 2>/dev/null || true
    semanage fcontext -a -t httpd_sys_rw_content_t "${PM_DIR}/bootstrap/cache(/.*)?" 2>/dev/null || true
    restorecon -Rv "$PM_DIR" >/dev/null 2>&1 || true
    setsebool -P httpd_can_network_connect 1 || true
    log "    SELinux 放行完成"
else
    warn "    policycoreutils-python-utils 安装失败，SELinux 可能拦截访问（页面 403/500 时见部署文档 7.4）"
fi

# 防火墙放行 http
firewall-cmd --permanent --add-service=http >/dev/null 2>&1 || true
firewall-cmd --reload >/dev/null 2>&1 || true

# 启动服务
systemctl enable --now php-fpm >/dev/null 2>&1 || fail "php-fpm 启动失败"
systemctl enable --now httpd >/dev/null 2>&1 || fail "httpd 启动失败"
log "    php-fpm / httpd 已启动"

# =========================================================
# [9] crontab 定时调度（幂等追加）
#     注意：仅写入 crontab 不够，必须确保 cron 守护进程 crond 已安装并运行，
#           否则定时任务不会执行（crontab 是登记表，crond 才是执行者）。
#     调度条目写入应用运行用户 ${APP_USER}（PHP-FPM 用户），
#     便于 PM 系统"后台服务状态"页面以该用户身份自检 crontab 配置。
# =========================================================
log "[9/11] 配置 crontab 定时调度..."

# 安装 cronie（提供 crontab 命令与 crond 服务），并启动 crond
if ! command -v crontab >/dev/null 2>&1; then
    log "    未安装 cronie，正在安装..."
    dnf install -y cronie >/dev/null 2>&1 || fail "cronie 安装失败，定时调度无法工作"
fi
systemctl enable --now crond >/dev/null 2>&1 || warn "    crond 服务启动失败，定时调度无法工作（请手动执行: systemctl enable --now crond）"
log "    crond 服务已启动（systemctl status crond 可确认）"

CRON_LINE="* * * * * cd ${PM_DIR} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"

# 迁移：若 root crontab 存在旧调度条目，则移除（避免与 ${APP_USER} 的条目重复执行）
if crontab -l 2>/dev/null | grep -Fq "artisan schedule:run"; then
    log "    检测到 root crontab 中的旧调度条目，迁移到 ${APP_USER} 用户..."
    crontab -l 2>/dev/null | grep -vF "artisan schedule:run" | crontab - 2>/dev/null || true
fi

# 将调度条目写入应用运行用户（crontab -u 需要 root 权限，本脚本以 root 执行）
if crontab -u "${APP_USER}" -l 2>/dev/null | grep -Fq "artisan schedule:run"; then
    log "    ${APP_USER} 的 crontab 已包含调度任务，跳过"
else
    ( crontab -u "${APP_USER}" -l 2>/dev/null || true; echo "$CRON_LINE" ) | crontab -u "${APP_USER}" -
    log "    已写入 ${APP_USER} 的 crontab（每分钟触发 schedule:run）"
fi

# =========================================================
# [10] 队列 systemd 服务（幂等）
# =========================================================
log "[10/11] 创建并启动队列服务 pm-queue..."

QUEUE_UNIT="/etc/systemd/system/pm-queue.service"
if [ ! -f "$QUEUE_UNIT" ]; then
    cat > "$QUEUE_UNIT" <<UNIT
[Unit]
Description=PM Queue Worker
After=network.target ${MYSQL_SVC}.service php-fpm.service

[Service]
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${PM_DIR}
ExecStart=/usr/bin/php artisan queue:work --tries=3 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    log "    pm-queue.service 已创建"
fi
systemctl enable --now pm-queue >/dev/null 2>&1 || warn "    pm-queue 启动失败（可稍后手动 systemctl start pm-queue 排查）"

# =========================================================
# [11] OnlyOffice Document Server（可选组件，默认部署）
#      对应手动部署第 9 节；INSTALL_ONLYOFFICE=0 可跳过
# =========================================================
if [ "$INSTALL_ONLYOFFICE" = "1" ]; then
    log "[11/11] 部署 OnlyOffice Document Server（文档第 9 节）..."

    # 硬件要求提醒（文档 9.3：最低 2 vCPU / 2GB 内存 / 10GB 磁盘）
    MEM_TOTAL_GB="$(awk '/MemTotal/ {printf "%.0f", $2/1024/1024}' /proc/meminfo 2>/dev/null)"
    [ "${MEM_TOTAL_GB:-0}" -ge 2 ] || warn "    当前内存 ${MEM_TOTAL_GB:-?}GB < 2GB，Document Server 可能无法正常运行（建议 ≥2GB）"
    DISK_AVAIL_GB="$(df -B1G / 2>/dev/null | awk 'NR==2 {print $4}' | tr -dc '0-9' || true)"
    if [ -n "$DISK_AVAIL_GB" ] && [ "$DISK_AVAIL_GB" -lt 10 ] 2>/dev/null; then
        warn "    根分区可用空间 ${DISK_AVAIL_GB}GB < 10GB（建议 ≥10GB）"
    fi

    # ① 安装并启动 Docker
    log "    [11.1] 检查 Docker..."
    if ! command -v docker >/dev/null 2>&1; then
        log "        未安装 Docker，正在安装..."
        dnf install -y docker >/dev/null 2>&1 || fail "Docker 安装失败，请检查软件源"
    fi
    # healthcheck 验证依赖 curl
    command -v curl >/dev/null 2>&1 || dnf install -y curl >/dev/null 2>&1 || warn "    curl 不可用，healthcheck 验证将无法执行（不影响部署本身）"
    if ! systemctl is-active --quiet docker; then
        systemctl enable --now docker >/dev/null 2>&1 || fail "Docker 服务启动失败"
    fi
    for i in $(seq 1 15); do
        if docker info >/dev/null 2>&1; then break; fi
        sleep 1
    done
    docker info >/dev/null 2>&1 || fail "Docker daemon 未就绪，请检查 docker 服务状态"
    log "    Docker 就绪"

    # ② 解析 JWT 密钥（.env → 已有容器 → 自动生成）
    log "    [11.2] 解析 JWT 密钥..."
    if [ -n "$JWT_SECRET" ]; then
        log "        使用脚本变量指定的 JWT_SECRET"
    elif [ -f "$ENV_FILE" ] && grep -q '^ONLYOFFICE_JWT_SECRET=.\+' "$ENV_FILE"; then
        JWT_SECRET="$(grep '^ONLYOFFICE_JWT_SECRET=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
        log "        复用 PM .env 中的 ONLYOFFICE_JWT_SECRET（保证与 PM 侧一致）"
    elif docker inspect "$DS_CONTAINER" >/dev/null 2>&1; then
        JWT_SECRET="$(docker inspect "$DS_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | sed -n 's/^JWT_SECRET=//p' | head -n1 || true)"
        [ -n "$JWT_SECRET" ] || fail "已有容器 ${DS_CONTAINER} 未设置 JWT_SECRET，请手动删除容器后重试"
        log "        复用已有容器 ${DS_CONTAINER} 的 JWT_SECRET"
    else
        JWT_SECRET="$(openssl rand -hex 32)"
        log "        已自动生成 JWT_SECRET（64 位十六进制）"
    fi
    [ "${#JWT_SECRET}" -ge 16 ] || fail "JWT_SECRET 过短（<16 字符），请设置足够长的随机字符串"

    # ③ 创建并启动 Document Server 容器
    log "    [11.3] 创建并启动 ${DS_CONTAINER} 容器..."
    if docker inspect "$DS_CONTAINER" >/dev/null 2>&1; then
        CUR_JWT="$(docker inspect "$DS_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | sed -n 's/^JWT_SECRET=//p' | head -n1 || true)"
        if [ -n "$CUR_JWT" ] && [ "$CUR_JWT" = "$JWT_SECRET" ]; then
            log "        容器已存在且 JWT 一致，跳过创建"
            docker start "$DS_CONTAINER" >/dev/null 2>&1 || true
        else
            warn "        容器已存在但 JWT 与目标不一致，删除并重建（协同文档保存在 PM 侧 storage，不受影响）"
            docker rm -f "$DS_CONTAINER" >/dev/null 2>&1 || true
        fi
    fi

    if ! docker inspect "$DS_CONTAINER" >/dev/null 2>&1; then
        # 镜像不存在则先拉取（首次约数百 MB，视网络情况可能需要几分钟）
        if ! docker image inspect "$DS_IMAGE" >/dev/null 2>&1; then
            log "        正在拉取镜像 ${DS_IMAGE}（首次约数百 MB，请耐心等待）..."
            docker pull "$DS_IMAGE" || fail "镜像拉取失败，请检查网络（可更换镜像源后重试）"
        fi
        # host.docker.internal 必须指向宿主机（PM 所在机器）：
        #   ① 优先 Docker 20.10+ 的 host-gateway 特殊值（自动解析为宿主在容器网络中的地址，兼容自定义网桥）；
        #   ② 旧版 Docker 回退到 docker0 网桥宿主侧 IP（172.17.0.1）。
        #   ⚠️ 禁止用 `ip route show default` 取默认网关：那通常是路由器（如 192.168.2.1），
        #      会导致 DS 下载/保存回调打到路由器而报"下载失败"。
        DOCKER_MAJOR="$(docker version --format '{{.Server.Major}}' 2>/dev/null || true)"
        DOCKER_MINOR="$(docker version --format '{{.Server.Minor}}' 2>/dev/null || true)"
        if [ "${DOCKER_MAJOR:-0}" -gt 20 ] || { [ "${DOCKER_MAJOR:-0}" -eq 20 ] && [ "${DOCKER_MINOR:-0}" -ge 10 ]; }; then
            HOST_GATEWAY="host-gateway"
        else
            HOST_GATEWAY="$(ip -4 addr show docker0 2>/dev/null | awk '/inet / {print $2}' | cut -d/ -f1 | head -n1)"
            [ -n "$HOST_GATEWAY" ] || HOST_GATEWAY="172.17.0.1"
        fi

        docker run -d --name "$DS_CONTAINER" \
            --restart=always \
            -p "${DS_PORT}:80" \
            --add-host "host.docker.internal:${HOST_GATEWAY}" \
            -e JWT_ENABLED=true \
            -e JWT_SECRET="$JWT_SECRET" \
            "$DS_IMAGE" || fail "容器创建失败（端口 ${DS_PORT} 可能被占用，请先排查占用进程）"
        log "        容器已创建: ${DS_CONTAINER}（${DS_PORT} → 容器内 80）"
    fi

    # 等待容器进入 running 状态
    for i in $(seq 1 30); do
        if [ "$(docker inspect -f '{{.State.Running}}' "$DS_CONTAINER" 2>/dev/null)" = "true" ]; then break; fi
        sleep 2
    done
    [ "$(docker inspect -f '{{.State.Running}}' "$DS_CONTAINER" 2>/dev/null)" = "true" ] || fail "容器未进入运行状态，请执行 docker logs ${DS_CONTAINER} 查看日志"

    # ④ 安装中文字体（防中文显示为方块）
    log "    [11.4] 检查中文字体..."
    if docker exec "$DS_CONTAINER" bash -c "command -v fc-list >/dev/null 2>&1 && fc-list 2>/dev/null | grep -qiE 'wqy|wenquanyi'" 2>/dev/null; then
        log "        已安装中文字体（WenQuanYi），跳过"
    else
        log "        正在安装中文字体（fonts-wqy-zenhei / fonts-wqy-microhei）..."
        if docker exec "$DS_CONTAINER" bash -c "apt-get update >/dev/null 2>&1 && apt-get install -y fonts-wqy-zenhei fonts-wqy-microhei >/dev/null 2>&1 && rm -rf /var/lib/apt/lists/*"; then
            docker restart "$DS_CONTAINER" >/dev/null 2>&1 || true
            log "        中文字体安装完成，容器已重启"
        else
            warn "        中文字体安装失败（容器内网络问题？），中文可能显示为方块；可稍后手动执行文档 9.3 的命令并重启容器"
        fi
    fi

    # ⑤ 防火墙放行 8080
    log "    [11.5] 防火墙放行 ${DS_PORT}/tcp..."
    if command -v firewall-cmd >/dev/null 2>&1; then
        firewall-cmd --permanent --add-port="${DS_PORT}/tcp" >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 || true
        log "        已放行 ${DS_PORT}/tcp"
    else
        warn "        未检测到 firewall-cmd，跳过防火墙配置（请确认服务器防火墙/安全组已放行 ${DS_PORT} 端口）"
    fi

    # ⑥ 配置 PM 系统 .env（三个 OnlyOffice 变量）
    log "    [11.6] 配置 PM 系统 .env..."
    SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [ -n "$SERVER_IP" ] || SERVER_IP="127.0.0.1"

    # ONLYOFFICE_SERVER_URL：浏览器访问 DS 的地址
    if [ -n "$DS_PUBLIC_URL" ]; then
        sed -i "s|^ONLYOFFICE_SERVER_URL=.*|ONLYOFFICE_SERVER_URL=${DS_PUBLIC_URL}|" "$ENV_FILE"
        grep -q '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" || echo "ONLYOFFICE_SERVER_URL=${DS_PUBLIC_URL}" >> "$ENV_FILE"
        log "        写入 ONLYOFFICE_SERVER_URL=${DS_PUBLIC_URL}（脚本变量指定）"
    elif grep -q '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" && ! grep -q '^ONLYOFFICE_SERVER_URL=http://localhost' "$ENV_FILE"; then
        log "        保留 .env 已有 ONLYOFFICE_SERVER_URL: $(grep '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" | cut -d= -f2-)"
    else
        sed -i "s|^ONLYOFFICE_SERVER_URL=.*|ONLYOFFICE_SERVER_URL=http://${SERVER_IP}:${DS_PORT}|" "$ENV_FILE"
        grep -q '^ONLYOFFICE_SERVER_URL=' "$ENV_FILE" || echo "ONLYOFFICE_SERVER_URL=http://${SERVER_IP}:${DS_PORT}" >> "$ENV_FILE"
        log "        写入 ONLYOFFICE_SERVER_URL=http://${SERVER_IP}:${DS_PORT}"
    fi

    # ONLYOFFICE_JWT_SECRET：必须与容器完全一致（否则打开编辑器报 JWT/401）
    sed -i "s|^ONLYOFFICE_JWT_SECRET=.*|ONLYOFFICE_JWT_SECRET=${JWT_SECRET}|" "$ENV_FILE"
    grep -q '^ONLYOFFICE_JWT_SECRET=' "$ENV_FILE" || echo "ONLYOFFICE_JWT_SECRET=${JWT_SECRET}" >> "$ENV_FILE"
    log "        写入 ONLYOFFICE_JWT_SECRET（与容器 JWT 一致）"

    # ONLYOFFICE_PM_INTERNAL_URL：DS 服务器访问 PM 的地址
    if [ -n "$PM_INTERNAL_URL" ]; then
        sed -i "s|^ONLYOFFICE_PM_INTERNAL_URL=.*|ONLYOFFICE_PM_INTERNAL_URL=${PM_INTERNAL_URL}|" "$ENV_FILE"
        grep -q '^ONLYOFFICE_PM_INTERNAL_URL=' "$ENV_FILE" || echo "ONLYOFFICE_PM_INTERNAL_URL=${PM_INTERNAL_URL}" >> "$ENV_FILE"
        log "        写入 ONLYOFFICE_PM_INTERNAL_URL=${PM_INTERNAL_URL}（脚本变量指定）"
    elif grep -q '^ONLYOFFICE_PM_INTERNAL_URL=.\+' "$ENV_FILE"; then
        CUR_INTERNAL_URL="$(grep '^ONLYOFFICE_PM_INTERNAL_URL=' "$ENV_FILE" | head -n1 | cut -d= -f2-)"
        if echo "$CUR_INTERNAL_URL" | grep -qE '^[a-zA-Z][a-zA-Z0-9+.-]*://[^:/]+:[0-9]+'; then
            FIXED_INTERNAL_URL="$(echo "$CUR_INTERNAL_URL" | sed -E 's|^([a-zA-Z][a-zA-Z0-9+.-]*://[^:/]+):[0-9]+(.*)$|\1\2|')"
            warn "        .env 已有 ONLYOFFICE_PM_INTERNAL_URL=${CUR_INTERNAL_URL} 带端口，自动改为 ${FIXED_INTERNAL_URL}（本脚本以 Apache 80 端口部署 PM，无需带端口）"
            sed -i "s|^ONLYOFFICE_PM_INTERNAL_URL=.*|ONLYOFFICE_PM_INTERNAL_URL=${FIXED_INTERNAL_URL}|" "$ENV_FILE"
            log "        已修正 ONLYOFFICE_PM_INTERNAL_URL=${FIXED_INTERNAL_URL}"
        else
            log "        保留 .env 已有 ONLYOFFICE_PM_INTERNAL_URL: ${CUR_INTERNAL_URL}"
            warn "        请确认该地址在 Document Server 容器内可访问到 PM（PM 监听 80 端口时无需带端口）"
        fi
    else
        # 默认写宿主机 IP：DS 容器与 PM 同机时容器内可直接访问宿主机 IP，不依赖 host.docker.internal 映射
        echo "ONLYOFFICE_PM_INTERNAL_URL=http://${SERVER_IP}" >> "$ENV_FILE"
        log "        写入 ONLYOFFICE_PM_INTERNAL_URL=http://${SERVER_IP}"
    fi

    # 若已生成 Laravel 配置缓存则刷新（文档 9.5）
    if [ -f "${PM_DIR}/bootstrap/cache/config.php" ]; then
        ( cd "$PM_DIR" && php artisan config:cache >/dev/null 2>&1 ) || warn "        config:cache 刷新失败，可稍后手动执行: cd ${PM_DIR} && php artisan config:cache"
        log "        已刷新 Laravel 配置缓存"
    else
        log "        未发现配置缓存，无需刷新"
    fi

    # ⑦ 验证安装（healthcheck）
    log "    [11.7] 验证安装（healthcheck）..."
    DS_OK=0
    for i in $(seq 1 36); do   # 最多等 3 分钟（容器首次初始化较慢）
        if curl -sf "http://127.0.0.1:${DS_PORT}/healthcheck" 2>/dev/null | grep -q 'true'; then
            DS_OK=1
            break
        fi
        sleep 5
    done
    if [ "$DS_OK" = "1" ]; then
        log "        healthcheck 返回 true，Document Server 运行正常"
    else
        warn "        healthcheck 未返回 true，容器可能仍在初始化或内存不足；请执行 docker logs ${DS_CONTAINER} 排查（文档 9.7）"
    fi
else
    log "[11/11] 已跳过 OnlyOffice Document Server 部署（INSTALL_ONLYOFFICE=0）"
fi

# =========================================================
# 收尾
# =========================================================
echo ""
log "========== PM 一键部署完成 =========="
echo ""
echo "  访问地址 : http://$(hostname -I 2>/dev/null | awk '{print $1}')   （或服务器实际 IP）"
echo "  项目目录 : ${PM_DIR}"
echo "  数据库   : ${DB_NAME}（root / ${DB_PASS}）"
echo "  定时任务 : crontab -u ${APP_USER} -l 查看；队列服务 : systemctl status pm-queue"
echo "  管理员账号: 由预置数据创建（如不清楚默认账号密码，可执行 php artisan tinker 查询）"
if [ "$INSTALL_ONLYOFFICE" = "1" ]; then
    SERVER_IP_OUT="$(hostname -I 2>/dev/null | awk '{print $1}')"
    [ -n "$SERVER_IP_OUT" ] || SERVER_IP_OUT="服务器IP"
    echo "  OnlyOffice: http://${SERVER_IP_OUT}:${DS_PORT}（欢迎页 /welcome；JWT 密钥: ${JWT_SECRET}）"
    echo "              docker logs ${DS_CONTAINER} / docker restart ${DS_CONTAINER}"
fi
echo ""
warn "提示：生产环境建议修改 MySQL root 密码并改用专用低权限数据库账号（见部署文档第四节）"
if [ "$INSTALL_ONLYOFFICE" = "1" ]; then
    warn "提示：OnlyOffice 公网部署建议配置 HTTPS 反向代理（Apache 443 → 127.0.0.1:${DS_PORT}，见文档 9.4），并将 ONLYOFFICE_SERVER_URL 改为 https 地址；社区版最多 20 个同时编辑连接"
fi
