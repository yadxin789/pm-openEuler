#!/bin/bash
#=============================================================
# PM 系统重装后一键部署脚本（openEuler）
# 用法：root 用户执行 bash deploy_pm.sh
# 前提：已备份数据（storage 目录 + SQL 数据库）
#=============================================================
set -e

DATA_DIR="/data"
PM_DIR="/var/www/pm"
MYSQL_DATA="/var/lib/mysql"

echo "=========================================="
echo " PM 系统部署脚本"
echo "=========================================="

# ---- 1. 挂载数据分区（如果还没挂载）----
echo "[1/8] 检查数据分区..."
if ! mountpoint -q "$DATA_DIR"; then
    echo "  $DATA_DIR 未挂载，查找数据分区..."
    # 自动查找 LVM 中的 data 卷，或第二块盘
    DATA_DEV=$(blkid -L "pm-data" 2>/dev/null || lsblk -ln -o NAME,SIZE,FSTYPE | grep -E 'ext4|xfs' | grep -v boot | grep -v '^sda[12]' | head -1 | awk '{print "/dev/"$1}')
    if [ -z "$DATA_DEV" ]; then
        echo "  错误：找不到数据分区。请手动确认数据盘设备名。"
        echo "  可用设备："
        lsblk
        exit 1
    fi
    mkdir -p "$DATA_DIR"
    mount "$DATA_DEV" "$DATA_DIR"
    # 写入 fstab 实现开机自动挂载
    UUID=$(blkid -s UUID -o value "$DATA_DEV")
    echo "UUID=$UUID $DATA_DIR ext4 defaults 0 0" >> /etc/fstab
    echo "  已挂载 $DATA_DEV -> $DATA_DIR"
fi

# ---- 2. 安装基础软件包 ----
echo "[2/9] 安装系统依赖..."
dnf install -y php php-fpm php-mysqlnd php-mbstring php-xml php-zip php-gd \
    php-bcmath php-fileinfo php-curl php-pdo php-json \
    httpd mariadb-server mariadb \
    git composer poppler-utils \
    zip unzip curl openvpn

# ---- 3. 配置防火墙 ----
echo "[3/9] 配置防火墙..."
firewall-cmd --permanent --add-service=http 2>/dev/null || true
firewall-cmd --permanent --add-service=https 2>/dev/null || true
firewall-cmd --permanent --add-port=1194/udp 2>/dev/null || true
firewall-cmd --reload 2>/dev/null || true

# ---- 4. 配置并启动 MySQL（数据目录指向 /data/mysql）----
echo "[4/9] 配置 MariaDB 数据目录..."

# 先把 MySQL 数据目录迁移到 /data/mysql
# 如果 /data/mysql 已有数据（从备份恢复），直接配置 my.cnf
# 如果没有，初始化一个空库
MYSQL_OLD="/var/lib/mysql"
mkdir -p "$DATA_DIR/mysql"

# 配置 my.cnf 指向 /data/mysql
if ! grep -q "datadir=$DATA_DIR/mysql" /etc/my.cnf; then
    sed -i "s|^datadir=.*|datadir=$DATA_DIR/mysql|" /etc/my.cnf
    # 如果没有 datadir 行，追加
    grep -q "datadir" /etc/my.cnf || echo "datadir=$DATA_DIR/mysql" >> /etc/my.cnf
fi

# 如果旧数据目录有数据且新目录为空，迁移过去
if [ -d "$MYSQL_OLD/mysql" ] && [ -z "$(ls -A $DATA_DIR/mysql 2>/dev/null)" ]; then
    echo "  迁移 MySQL 数据到 $DATA_DIR/mysql..."
    systemctl stop mariadb 2>/dev/null || true
    rsync -a "$MYSQL_OLD/" "$DATA_DIR/mysql/"
    chown -R mysql:mysql "$DATA_DIR/mysql"
fi

systemctl enable --now mariadb
if [ -d "$DATA_DIR/mysql/mysql" ]; then
    echo "  检测到已有 MySQL 数据目录"
else
    echo "  初始化 MySQL（空库）"
    mysql_secure_installation <<EOF

y
root_password_2026
root_password_2026
y
y
y
y
EOF
fi

# ---- 5. 创建数据目录结构 ----
echo "[5/9] 创建数据目录..."
# /data/pm-storage 对应 Laravel 的 storage/app/
mkdir -p "$DATA_DIR/pm-storage"
# /data/mysql 对应 MySQL 数据目录
mkdir -p "$DATA_DIR/mysql"

# ---- 6. 恢复 PM 文件 ----
echo "[6/9] 恢复 PM 应用..."
if [ -d "$PM_DIR" ]; then
    echo "  $PM_DIR 已存在，git pull 更新"
    cd "$PM_DIR"
    git remote set-url origin /srv/git/pm.git 2>/dev/null || true
    git pull origin main
else
    echo "  克隆仓库..."
    git clone /srv/git/pm.git "$PM_DIR" 2>/dev/null || git clone git@github.com:yadxin789/pm-openEuler.git "$PM_DIR"
    cd "$PM_DIR"
    git checkout main
fi

# 安装 PHP 依赖
cd "$PM_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

# ===== 方案C：只隔离 storage/app，不隔离整个 storage =====
# storage/ 目录结构：
#   storage/app/       ← 用户上传文件，软链接到 /data/pm-storage（大数据）
#   storage/framework/ ← 缓存/session/视图编译，留系统盘（几MB）
#   storage/logs/      ← 日志，留系统盘（会轮转）
echo "  配置 storage/app 软链接到 $DATA_DIR/pm-storage..."
# 确保 storage/framework 和 storage/logs 存在
mkdir -p "$PM_DIR/storage/framework/cache/data"
mkdir -p "$PM_DIR/storage/framework/sessions"
mkdir -p "$PM_DIR/storage/framework/views"
mkdir -p "$PM_DIR/storage/logs"

# 如果 storage/app 还是真实目录，先把里面的内容迁移到 /data/pm-storage
if [ -d "$PM_DIR/storage/app" ] && [ ! -L "$PM_DIR/storage/app" ]; then
    echo "  迁移现有 storage/app 内容到 $DATA_DIR/pm-storage..."
    rsync -a "$PM_DIR/storage/app/" "$DATA_DIR/pm-storage/"
    rm -rf "$PM_DIR/storage/app"
fi

# 创建软链接：storage/app -> /data/pm-storage
if [ ! -L "$PM_DIR/storage/app" ]; then
    ln -s "$DATA_DIR/pm-storage" "$PM_DIR/storage/app"
fi
echo "  $PM_DIR/storage/app -> $DATA_DIR/pm-storage"

# 配置 .env
if [ ! -f "$PM_DIR/.env" ]; then
    cp "$PM_DIR/.env.example" "$PM_DIR/.env"
    php artisan key:generate --force
fi

# 目录权限
chown -R apache:apache "$PM_DIR"
chmod -R 775 "$PM_DIR/storage" "$PM_DIR/bootstrap/cache"

# ---- 7. 配置 Apache ----
echo "[7/9] 配置 Apache..."
cat > /etc/httpd/conf.d/pm.conf <<'APACHE'
<VirtualHost *:80>
    DocumentRoot /var/www/pm/public
    <Directory /var/www/pm/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog /var/log/httpd/pm-error.log
    CustomLog /var/log/httpd/pm-access.log combined
</VirtualHost>
APACHE

systemctl enable --now httpd

# ---- 8. 配置 PHP-FPM 并启动 ----
echo "[8/9] 配置 PHP-FPM..."
sed -i 's/^user = .*/user = apache/' /etc/php-fpm.d/www.conf
sed -i 's/^group = .*/group = apache/' /etc/php-fpm.d/www.conf
systemctl enable --now php-fpm

# ---- 9. 配置 OpenVPN ----
echo "[9/9] 配置 OpenVPN..."
mkdir -p /etc/openvpn/server /var/log/openvpn
if [ ! -f /etc/openvpn/server/server.conf ]; then
    cat > /etc/openvpn/server/server.conf <<'OVPN'
port 1194
proto udp
dev tun
ca /etc/openvpn/ca.crt
cert /etc/openvpn/server.crt
key /etc/openvpn/server.key
dh /etc/openvpn/dh.pem
server 10.8.0.0 255.255.255.0
ifconfig-pool-persist /etc/openvpn/ipp.txt
push "dhcp-option DNS 223.5.5.5"
push "dhcp-option DNS 119.29.29.29"
keepalive 10 120
cipher AES-256-GCM
persist-key
persist-tun
status /var/log/openvpn/status.log
verb 3
OVPN
    echo "  注意：首次部署需手动生成 OpenVPN 证书（CA/server/dh），详见 README 6.4 节"
fi
systemctl enable --now openvpn-server@server 2>/dev/null || true

# 清理 Laravel 缓存
cd "$PM_DIR"
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo ""
echo "=========================================="
echo " 部署完成！"
echo "=========================================="
echo " PM 地址: http://192.168.2.20"
echo " 数据分区: $DATA_DIR"
echo " Storage: $PM_DIR/storage/app -> $DATA_DIR/pm-storage"
echo " MySQL:   $MYSQL_DATA -> $DATA_DIR/mysql"
echo " OpenVPN: UDP 1194（远程办公）"
echo "=========================================="
echo " 如果是全新安装，还需："
echo "   1. mysql -u root -p < 备份的数据库.sql"
echo "   2. php artisan migrate --force"
echo "   3. 导入备份的 storage 内容到 $DATA_DIR/pm-storage"
echo "   4. 生成 OpenVPN 证书（见 README 6.4 节）"
echo "   5. 配置 HTTPS 证书（见 README 6.5 节）"
echo "=========================================="
