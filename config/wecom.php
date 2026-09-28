<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 企业微信集成配置
    |--------------------------------------------------------------------------
    */

    // API 基础配置
    'api' => [
        'base_uri'          => env('WECOM_API_BASE', 'https://qyapi.weixin.qq.com'),
        'timeout'           => 30,
        'connect_timeout'   => 10,
    ],

    // OAuth 设置
    'oauth' => [
        'scopes'        => env('WECOM_OAUTH_SCOPE', 'snsapi_base'),
        'state_timeout' => env('WECOM_OAUTH_STATE_TIMEOUT', 300),
    ],

    // 文件互传设置
    'file' => [
        'max_upload_size'   => env('WECOM_FILE_MAX_SIZE', 20480),  // KB, 默认 20MB
        'allowed_types'     => ['image', 'voice', 'video', 'file'],
        'storage_disk'      => 'wecom_files',
        'storage_path'      => 'wecom-inbox',
        'temp_media_expire' => 3 * 86400,  // 临时素材有效期 3 天（秒）
    ],

    // 通知模板
    'templates' => [],
];
