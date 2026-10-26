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

    // AI 数据 API 访问密钥（供 AI / 外部 Agent 调取 PM 数据）
    'ai_api_key' => env('AI_API_KEY'),

    // AI 数据 API 行为控制
    'ai_api' => [
        // 是否允许不携带用户委托令牌的匿名只读调用。
        // 关闭后所有 /api/ai 接口都必须带 X-AI-Token，数据可见范围严格等同于该用户。
        'allow_anonymous_read' => env('AI_API_ALLOW_ANONYMOUS_READ', true),

        // 变更提案有效期（分钟）：超时未确认自动作废
        'proposal_ttl_minutes' => env('AI_PROPOSAL_TTL_MINUTES', 30),

        // 每分钟请求上限（无委托令牌时按 IP 限流）
        'rate_limit_per_minute' => env('AI_API_RATE_LIMIT', 120),
    ],

    // 回收站内容保留天数：软删除满该天数的记录会被定时任务自动彻底删除（含物理文件）
    'trash_retention_days' => env('PM_TRASH_RETENTION_DAYS', 15),

    // 任务文件历史版本保留个数：每次更新文件都会为旧版本生成独立快照，超出该数量后清理最旧的
    'file_version_keep' => env('PM_FILE_VERSION_KEEP', 3),

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
