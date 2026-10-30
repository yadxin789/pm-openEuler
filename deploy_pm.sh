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
echo "[2/8] 安装系统依赖..."
dnf install -y php php-fpm php-mysqlnd php-mbstring php-xml php-zip php-gd \
    php-bcmath php-fileinfo php-curl php-pdo php-json \
    nginx mariadb-server mariadb \
    git composer poppler-utils \
    zip unzip curl

# ---- 3. 配置防火墙 ----
echo "[3/8] 配置防火墙..."
firewall-cmd --permanent --add-service=http 2>/dev/null || true
firewall-cmd --permanent --add-service=https 2>/dev/null || true
firewall-cmd --reload 2>/dev/null || true

# ---- 4. 启动 MySQL ----
echo "[4/8] 启动 MariaDB..."
systemctl enable --now mariadb
# 如果数据目录已从备份恢复，这里会自动识别
if [ -d "$MYSQL_DATA/mysql" ]; then
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
echo "[5/8] 创建数据目录..."
mkdir -p "$DATA_DIR/pm-storage"
mkdir -p "$DATA_DIR/mysql"

# ---- 6. 恢复 PM 文件 ----
echo "[6/8] 恢复 PM 应用..."
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

# 恢复 storage（从备份解压到 /data/pm-storage，再软链接）
if [ -d "$DATA_DIR/pm-storage" ] && [ "$(ls -A $DATA_DIR/pm-storage 2>/dev/null)" ]; then
    echo "  恢复 storage 到 $DATA_DIR/pm-storage"
    rm -rf "$PM_DIR/storage"
    ln -s "$DATA_DIR/pm-storage" "$PM_DIR/storage"
fi

# 配置 .env
if [ ! -f "$PM_DIR/.env" ]; then
    cp "$PM_DIR/.env.example" "$PM_DIR/.env"
    php artisan key:generate --force
fi

# 目录权限
chown -R nginx:nginx "$PM_DIR"
chmod -R 775 "$PM_DIR/storage" "$PM_DIR/bootstrap/cache"

# ---- 7. 配置 Nginx ----
echo "[7/8] 配置 Nginx..."
cat > /etc/nginx/conf.d/pm.conf <<'NGINX'
server {
    listen 80;
    server_name 192.168.2.20;
    root /var/www/pm/public;
    index index.php;

    client_max_body_size 100M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINX

systemctl enable --now nginx

# ---- 8. 配置 PHP-FPM 并启动 ----
echo "[8/8] 配置 PHP-FPM..."
# 确保 php-fpm 以 nginx 用户运行
sed -i 's/^user = .*/user = nginx/' /etc/php-fpm.d/www.conf
sed -i 's/^group = .*/group = nginx/' /etc/php-fpm.d/www.conf
systemctl enable --now php-fpm

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
echo " PM 地址: https://192.168.2.20"
echo " 数据分区: $DATA_DIR"
echo " Storage: $PM_DIR/storage -> $DATA_DIR/pm-storage"
echo ""
echo " 如果是全新安装，还需："
echo "   1. mysql -u root -p < 备份的数据库.sql"
echo "   2. php artisan migrate --force"
echo "   3. 导入备份的 storage 到 $DATA_DIR/pm-storage"
echo "=========================================="
