<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OnlyOffice DocumentServer 配置
    |--------------------------------------------------------------------------
    |
    | server_url       : 浏览器访问 OnlyOffice 的地址（本机 Docker 容器 8080）
    | jwt_secret       : 与 OnlyOffice 容器启动时 JWT_SECRET 保持一致
    | pm_internal_url  : OnlyOffice 服务器（容器内）访问本 PM 系统的地址。
    |                    Docker Desktop(WSL2) 容器内经 host.docker.internal 访问宿主机；
    |                    PM 系统以 php artisan serve 或 XAMPP 提供 HTTP 服务。
    */

    'server_url' => env('ONLYOFFICE_SERVER_URL', 'http://localhost:8080'),

    'jwt_secret' => env('ONLYOFFICE_JWT_SECRET', ''),

    'pm_internal_url' => env('ONLYOFFICE_PM_INTERNAL_URL', 'http://host.docker.internal:8000'),

    // 内置模板文件目录（相对 storage/app 下）
    'template_dir' => 'templates',

];
