#!/usr/bin/env bash
# ============================================================================
#  deploy-views.sh —— 服务器端一键同步代码更新（git pull + 权限 + 清缓存）
#
#  作用：把本地已提交的代码变更同步到服务器正在运行的项目，并让新模板生效。
#        （等同于原先"打包 + 覆盖 + 清缓存"的整套流程，改用 git pull 完成"覆盖"）
#
#  适用环境：openEuler / Linux + Laravel，项目部署于 /var/www/pm
#
#  用法：
#     ./deploy-views.sh             # 拉取默认分支 master + 修正属主 + 清理并重建视图缓存
#     ./deploy-views.sh main        # 指定拉取分支
#     ./deploy-views.sh main 0      # 不修正目录属主
#     ./deploy-views.sh main 1 0    # 只清理缓存、不重建
#
#  前提：
#     - 服务器项目目录已是 git 工作副本并配置好 origin（一次性初始化，见部署说明）
#     - 以 root 或具备项目写权限的用户执行
#
#  注意：
#     - 从 Windows 拷贝本文件到服务器后若执行报错（换行符问题），先执行：
#         sed -i 's/\r$//' deploy-views.sh
#       若仓库已含 .gitattributes（*.sh eol=lf），git pull 下来的就是 LF，无需此步。
#     - 本脚本只同步代码与清缓存，不执行 composer install / npm run build /
#       migrate（仅在改动涉及依赖/前端/数据库时才需另行处理）。
# ============================================================================
set -euo pipefail

# 项目部署路径（可用环境变量覆盖，如  APP_DIR=/opt/pm ./deploy-views.sh）
APP_DIR="${APP_DIR:-/var/www/pm}"
# 默认拉取分支（可用环境变量覆盖，如 BRANCH=main）
BRANCH="${BRANCH:-master}"
REOWN="${REOWN:-1}"   # 1=修正目录属主，0=跳过
PHP_USER="${PHP_USER:-pmadmin}"   # PHP-FPM 实际运行用户（请按服务器实际配置设置，例：pmadmin / nginx / www-data）
CACHE="${CACHE:-1}"   # 1=清理并重建视图缓存，0=仅清理不重建

if [ ! -d "$APP_DIR/.git" ]; then
    echo "[错误] $APP_DIR 不是 git 仓库，请先完成服务器一次性初始化（见部署说明）。" >&2
    exit 1
fi

if [ ! -f "$APP_DIR/artisan" ]; then
    echo "[错误] $APP_DIR 下未找到 artisan，请确认项目路径是否正确。" >&2
    exit 1
fi

cd "$APP_DIR"

# ---------- 1. 拉取最新代码（--ff-only 避免服务器上产生合并冲突） ----------
echo "==> git pull --ff-only origin $BRANCH"
git pull --ff-only origin "$BRANCH"

# ---------- 2. 修正目录属主（与 PHP-FPM 运行用户一致） ----------
if [ "$REOWN" = "1" ]; then
    echo "==> 修正目录属主为 $PHP_USER:$PHP_USER（PHP-FPM 运行用户）"
    chown -R "$PHP_USER:$PHP_USER" "$APP_DIR"
fi

# ---------- 3. 清理视图缓存 ----------
echo "==> 清理视图缓存 (view:clear)"
php artisan view:clear

# ---------- 4. 按需重建视图缓存（生产环境建议重建） ----------
if [ "$CACHE" = "1" ]; then
    echo "==> 重建视图缓存 (view:cache)"
    php artisan view:cache
fi

echo ""
echo "==> 完成。请浏览器 Ctrl+F5 强制刷新查看最新页面。"
