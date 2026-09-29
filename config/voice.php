<?php

return [
    // Swoole WebSocket 信令服务对外地址（浏览器连接用）
    'signal_ws' => env('VOICE_SIGNAL_WS', 'wss://127.0.0.1:3001'),

    // Janus WebSocket 信令地址（仅 Swoole 服务端连本机，浏览器不直接连）
    'janus_ws' => env('VOICE_JANUS_WS', 'ws://127.0.0.1:8188'),

    // Janus Admin REST（Laravel 调用来创建/销毁 AudioBridge room）
    'janus_admin' => env('VOICE_JANUS_ADMIN', 'http://127.0.0.1:7088'),
    'janus_admin_secret' => env('VOICE_JANUS_ADMIN_SECRET', 'janusadmin'),

    // STUN/TURN（纯内网，通常不需要 TURN）
    'stun' => env('VOICE_STUN', 'stun:127.0.0.1:3478'),
    'turn' => env('VOICE_TURN'),
    'turn_secret' => env('VOICE_TURN_SECRET'),

    // 入场 token TTL（秒）
    'token_ttl' => env('VOICE_TOKEN_TTL', 600),

    // Swoole 服务监听
    'signal_listen' => env('VOICE_SIGNAL_LISTEN', '0.0.0.0'),
    'signal_port' => (int) env('VOICE_SIGNAL_PORT', 3001),

    // Swoole 服务回调 Laravel 的内网地址
    'pm_internal_url' => env('VOICE_PM_INTERNAL_URL', 'http://127.0.0.1'),
];
