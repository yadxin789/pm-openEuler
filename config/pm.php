<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 项目管理系统配置
    |--------------------------------------------------------------------------
    */
    'max_upload_size' => env('PM_MAX_UPLOAD_SIZE', 20480), // KB
    'upload_allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'txt', 'csv', 'zip', 'rar', '7z'],
    'default_pagination' => env('PM_DEFAULT_PAGINATION', 15),
    'default_project_prefix' => env('PM_DEFAULT_PROJECT_PREFIX', 'PRJ'),
    'chat_poll_interval' => env('PM_CHAT_POLL_INTERVAL', 10000), // ms
    'login_max_attempts' => env('PM_LOGIN_MAX_ATTEMPTS', 5),
    'login_decay_minutes' => env('PM_LOGIN_DECAY_MINUTES', 15),
    'backup_retention_days' => env('PM_BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | 用户注册配置
    |--------------------------------------------------------------------------
    */
    'registration' => [
        'open' => env('PM_REGISTRATION_OPEN', true),
        'default_role' => env('PM_DEFAULT_REGISTER_ROLE', 'task_executor'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 扩展配置
    |--------------------------------------------------------------------------
    */
    'extension' => [
        'max_days' => env('PM_EXTENSION_MAX_DAYS', 90),
    ],
];
