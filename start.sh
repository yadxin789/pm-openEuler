#!/usr/bin/env bash
# ============================================================================
#  PM 项目管理系统 - 开发环境启动脚本（openEuler / Linux）
#
#  与 Windows 版 start.bat 功能一致，同时启动以下服务：
#    1. Queue Worker    (php artisan queue:work --tries=3 --sleep=3)
#    2. Task Scheduler  (php artisan schedule:work)
#    3. Vite Dev Server (npm run dev)
#  按 Ctrl+C 可停止所有服务；任一服务异常退出时其余服务也会自动停止。
#
#  使用方法（服务器上执行）：
#    chmod +x start.sh
#    ./start.sh
#
#  注意：
#    - 若从 Windows 复制本文件到服务器后执行报错（换行符问题），先执行：
#        sed -i 's/\r$//' start.sh
#    - 本脚本仅用于【开发环境】。生产环境部署请按《pm项目部署详细步骤.md》
#      使用 systemd 托管队列（第八节）、crontab 执行定时调度、
#      npm run build 构建前端静态资源。
# ============================================================================

set -u

# 切换到脚本所在目录（兼容从任意目录执行）
cd "$(dirname "$0")" || { echo "[错误] 无法进入项目目录"; exit 1; }

echo "============================================"
echo " PM 项目管理系统 - 启动中..."
echo "============================================"
echo ""
echo "正在启动以下服务:"
echo "  1. Queue Worker (php artisan queue:work)"
echo "  2. Task Scheduler (php artisan schedule:work)"
echo "  3. Vite Dev Server (npm run dev)"
echo ""
echo "按 Ctrl+C 停止所有服务"
echo "============================================"
echo ""

# ---------- 前置检查 ----------

# 检查 php
if ! command -v php >/dev/null 2>&1; then
    echo "[错误] 未找到 php 命令，请先安装 PHP 8.3（见部署文档第三节）"
    exit 1
fi

# 检查 npm
if ! command -v npm >/dev/null 2>&1; then
    echo "[错误] 未找到 npm 命令，请先安装 Node.js（见部署文档第五节）"
    exit 1
fi

# 检查 vendor（php artisan 依赖）
if [ ! -f "vendor/autoload.php" ]; then
    echo "[错误] vendor 目录不存在，请先执行: composer install"
    exit 1
fi

# 检查 npm 依赖
if [ ! -d "node_modules" ]; then
    echo "[警告] node_modules 不存在，正在运行 npm install..."
    if ! npm install; then
        echo "[错误] npm install 执行失败"
        exit 1
    fi
fi

# ---------- 启动三个服务（后台运行） ----------

php artisan queue:work --tries=3 --sleep=3 &
QUEUE_PID=$!

php artisan schedule:work &
SCHEDULER_PID=$!

npm run dev &
VITE_PID=$!

echo "服务已启动: queue=$QUEUE_PID  scheduler=$SCHEDULER_PID  vite=$VITE_PID"
echo ""

# ---------- 停止与守护逻辑 ----------

# 捕获 Ctrl+C / 终止信号，停止所有子进程
cleanup() {
    echo ""
    echo "正在停止所有服务..."
    kill "$QUEUE_PID" "$SCHEDULER_PID" "$VITE_PID" 2>/dev/null
    wait 2>/dev/null
    echo "所有服务已停止"
    exit 0
}
trap cleanup INT TERM

# 前台循环守护：任一服务退出则整体停止
while true; do
    for pid in "$QUEUE_PID" "$SCHEDULER_PID" "$VITE_PID"; do
        if ! kill -0 "$pid" 2>/dev/null; then
            echo ""
            echo "[提示] 检测到服务进程已退出 (pid=$pid)，正在停止其余服务..."
            kill "$QUEUE_PID" "$SCHEDULER_PID" "$VITE_PID" 2>/dev/null
            wait 2>/dev/null
            exit 1
        fi
    done
    sleep 2
done
