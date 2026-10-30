#!/bin/bash
#=============================================================
# PM 系统重装前数据备份脚本
# 在旧系统上执行，备份到 /tmp/pm-backup/ 或外接U盘
# 方案C：只备份 storage/app（用户上传文件）和 MySQL 数据
#=============================================================
set -e

BACKUP_DIR="/tmp/pm-backup-$(date +%Y%m%d)"
echo "备份目录: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

# 1. 备份 PM 上传文件（只备份 storage/app，不备份 framework/logs）
echo "[1/3] 备份 storage/app（用户上传文件）..."
tar czf "$BACKUP_DIR/pm-storage.tar.gz" -C /var/www/pm/storage app/

# 2. 备份数据库
echo "[2/3] 备份数据库..."
mysqldump -u root -p --all-databases > "$BACKUP_DIR/all_databases.sql" 2>/dev/null || \
mysqldump -u root pm_db > "$BACKUP_DIR/pm_db.sql"

# 3. 备份配置
echo "[3/3] 备份配置文件..."
cp /var/www/pm/.env "$BACKUP_DIR/.env.bak" 2>/dev/null || true
cp /etc/my.cnf "$BACKUP_DIR/my.cnf.bak" 2>/dev/null || true
cp /etc/nginx/conf.d/pm.conf "$BACKUP_DIR/pm.nginx.conf.bak" 2>/dev/null || true

echo ""
echo "=========================================="
echo " 备份完成！"
echo " 备份位置: $BACKUP_DIR"
echo " 文件列表:"
ls -lh "$BACKUP_DIR/"
echo ""
echo " 请将此目录拷贝到U盘或其他机器后再重装系统！"
echo "=========================================="
