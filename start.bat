@echo off
chcp 65001 >nul
title PM 项目管理系统 - 开发环境

echo ============================================
echo  PM 项目管理系统 - 启动中...
echo ============================================
echo.
echo 正在启动以下服务:
echo   1. Queue Worker (php artisan queue:work)
echo   2. Task Scheduler (php artisan schedule:work)
echo   3. Vite Dev Server (npm run dev)
echo.
echo 按 Ctrl+C 停止所有服务
echo ============================================
echo.

cd /d "%~dp0"

REM 检查 npm 依赖
if not exist "node_modules" (
    echo [警告] node_modules 不存在，正在运行 npm install...
    call npm install
)

REM 使用 concurrently 同时启动所有服务
npx concurrently ^
    -c "#c4b5fd,#fb7185,#fdba74" ^
    "php artisan queue:work --tries=3 --sleep=3" ^
    "php artisan schedule:work" ^
    "npm run dev" ^
    --names=queue,scheduler,vite

pause
