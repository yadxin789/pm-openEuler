@echo off
chcp 65001 >nul
title PM系统证书导入工具
echo ==========================================
echo   PM系统 HTTPS 证书一键导入
echo ==========================================
echo.

:: 检查管理员权限
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [错误] 请右键此文件，选择"以管理员身份运行"！
    pause
    exit /b 1
)

echo 正在导入证书到 Windows 受信任根证书存储...
echo.

certutil -addstore -f Root "%~dp0pm-server.crt"

if %errorLevel% equ 0 (
    echo.
    echo ==========================================
    echo   导入成功！
    echo   请关闭浏览器后重新打开，访问 https://192.168.2.20
    echo   地址栏不再显示"不安全"警告。
    echo ==========================================
) else (
    echo.
    echo [失败] 导入出错，请联系管理员。
)

echo.
pause
