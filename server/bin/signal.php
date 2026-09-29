<?php
/**
 * 电话会议 Swoole WebSocket 信令服务（独立常驻进程）
 *
 * 职责：
 *   - 浏览器 wss 握手，校验 VoiceTokenService 签的短期 token
 *   - 内存维护 room_id => [fd => user_info] 映射
 *   - 广播成员上下线、presenter 变更、文档切换、翻页同步
 *   - presenter_assign/revoke 二次校验发送者必须是 host（项目创建人）
 *   - 连接关闭时若断线者是 presenter，自动回落给 host
 *   - 通过 HTTP 回调 Laravel /voice/hooks/{enter,leave} 写会议日志
 *
 * 启动：php /var/www/pm/server/bin/signal.php
 * 由 systemd pm-signal.service 托管，运行属主 pmadmin。
 */

use App\Models\VoiceRoom;
use App\Services\VoiceTokenService;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

// ---------- bootstrap Laravel（复用模型与 token service） ----------
$baseDir = dirname(__DIR__, 2);
require $baseDir . '/vendor/autoload.php';
$app = require $baseDir . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tokenSvc = app(VoiceTokenService::class);

// ---------- 内存状态 ----------
// connections[fd] = ['uid'=>int,'pid'=>int,'rid'=>int,'name'=>string,'is_host'=>bool]
$connections = [];
// rooms[rid] = [fd=>true]
$rooms = [];

$host = config('voice.signal_listen', '0.0.0.0');
$port = (int) config('voice.signal_port', 3001);

// Laravel 内网回调地址（Swoole 容器/进程访问 PM API）
$pmInternal = rtrim(env('VOICE_PM_INTERNAL_URL', 'http://127.0.0.1'), '/');

$server = new Server($host, $port);

$server->on('start', function (Server $srv) {
    echo '[signal] listening on ws://' . $srv->host . ':' . $srv->port . PHP_EOL;
});

$server->on('open', function (Server $srv, $request) use (&$connections, &$rooms, $tokenSvc, $pmInternal) {
    $fd = $request->fd;
    // query: ?token=xxx
    $query = [];
    parse_str($request->server['query_string'] ?? '', $query);
    $token = $query['token'] ?? '';
    $payload = $tokenSvc->verify($token);
    if (!$payload) {
        $srv->push($fd, json_encode(['type' => 'error', 'reason' => 'invalid_token']));
        $srv->disconnect($fd);
        return;
    }

    $rid = (int) $payload['rid'];
    $pid = (int) $payload['pid'];
    $uid = (int) $payload['uid'];

    $room = VoiceRoom::find($rid);
    if (!$room || $room->project_id !== $pid) {
        $srv->push($fd, json_encode(['type' => 'error', 'reason' => 'room_not_found']));
        $srv->disconnect($fd);
        return;
    }

    $user = \App\Models\User::find($uid);
    $connections[$fd] = [
        'uid' => $uid,
        'pid' => $pid,
        'rid' => $rid,
        'name' => $user?->name ?? ("用户{$uid}"),
        'is_host' => $uid === (int) $room->host_id,
    ];
    $rooms[$rid][$fd] = true;

    // 广播成员列表
    broadcastRoom($srv, $rid, $connections, $rooms);

    // HTTP 回调 Laravel 写日志（fire and forget）
    httpPostAsync($pmInternal . '/voice/hooks/enter', [
        'room_id' => $rid,
        'user_id' => $uid,
    ]);
});

$server->on('message', function (Server $srv, Frame $frame) use (&$connections, &$rooms) {
    $fd = $frame->fd;
    if (!isset($connections[$fd])) {
        return;
    }
    $me = $connections[$fd];
    $msg = json_decode($frame->data, true);
    if (!is_array($msg) || !isset($msg['type'])) {
        return;
    }
    $rid = $me['rid'];

    switch ($msg['type']) {
        case 'presenter_apply':
            // 听众举手：转发给 host
            sendToHost($srv, $rid, [
                'type' => 'presenter_apply',
                'from_uid' => $me['uid'],
                'from_name' => $me['name'],
            ], $connections, $rooms);
            break;

        case 'presenter_assign':
            // host 指派某人为 presenter
            if (!$me['is_host']) {
                pushOne($srv, $fd, ['type' => 'error', 'reason' => 'not_host']);
                return;
            }
            $targetUid = (int) ($msg['to_uid'] ?? 0);
            \App\Models\VoiceRoom::where('id', $rid)->update(['current_presenter_id' => $targetUid]);
            broadcast($srv, $rid, [
                'type' => 'presenter_changed',
                'presenter_uid' => $targetUid,
            ], $connections, $rooms);
            break;

        case 'presenter_revoke':
            if (!$me['is_host']) {
                pushOne($srv, $fd, ['type' => 'error', 'reason' => 'not_host']);
                return;
            }
            \App\Models\VoiceRoom::where('id', $rid)->update(['current_presenter_id' => $me['uid']]);
            broadcast($srv, $rid, [
                'type' => 'presenter_changed',
                'presenter_uid' => $me['uid'],
            ], $connections, $rooms);
            break;

        case 'doc_select':
            // host 或 presenter 切换演示文档
            $room = VoiceRoom::find($rid);
            if ($me['uid'] !== (int) $room->host_id && $me['uid'] !== (int) $room->current_presenter_id) {
                pushOne($srv, $fd, ['type' => 'error', 'reason' => 'not_permitted']);
                return;
            }
            broadcast($srv, $rid, [
                'type' => 'doc_selected',
                'doc_id' => (int) ($msg['doc_id'] ?? 0),
            ], $connections, $rooms);
            break;

        case 'slide_sync':
            // presenter 翻页/滚动位置同步
            $room = VoiceRoom::find($rid);
            if ($me['uid'] !== (int) $room->current_presenter_id) {
                return; // 非 presenter 静默丢弃
            }
            broadcast($srv, $rid, [
                'type' => 'slide_sync',
                'from_uid' => $me['uid'],
                'page' => $msg['page'] ?? null,
                'scroll' => $msg['scroll'] ?? null,
            ], $connections, $rooms, $fd); // 不回给 presenter 自己
            break;
    }
});

$server->on('close', function (Server $srv, $fd) use (&$connections, &$rooms, $pmInternal) {
    if (!isset($connections[$fd])) {
        return;
    }
    $me = $connections[$fd];
    $rid = $me['rid'];
    unset($connections[$fd], $rooms[$rid][$fd]);

    // 若断线者是 presenter，自动回落 host
    $room = VoiceRoom::find($rid);
    if ($room && (int) $room->current_presenter_id === (int) $me['uid']) {
        $room->update(['current_presenter_id' => $room->host_id]);
        broadcast($srv, $rid, [
            'type' => 'presenter_changed',
            'presenter_uid' => (int) $room->host_id,
        ], $connections, $rooms);
    }

    broadcastRoom($srv, $rid, $connections, $rooms);

    httpPostAsync($pmInternal . '/voice/hooks/leave', [
        'room_id' => $rid,
        'user_id' => $me['uid'],
        'reason' => 'normal',
    ]);
});

$server->start();

// ---------- 辅助函数 ----------

function broadcast(Server $srv, int $rid, array $payload, array $connections, array $rooms, ?int $excludeFd = null): void
{
    $data = json_encode($payload);
    foreach ($rooms[$rid] ?? [] as $fd => $_) {
        if ($fd === $excludeFd) continue;
        if ($srv->isConnected($fd)) {
            $srv->push($fd, $data);
        }
    }
}

function broadcastRoom(Server $srv, int $rid, array $connections, array $rooms): void
{
    $list = [];
    foreach ($rooms[$rid] ?? [] as $fd => $_) {
        if (isset($connections[$fd])) {
            $c = $connections[$fd];
            $list[] = [
                'uid' => $c['uid'],
                'name' => $c['name'],
                'is_host' => $c['is_host'],
            ];
        }
    }
    broadcast($srv, $rid, ['type' => 'room_state', 'members' => $list], $connections, $rooms);
}

function sendToHost(Server $srv, int $rid, array $payload, array $connections, array $rooms): void
{
    foreach ($rooms[$rid] ?? [] as $fd => $_) {
        if (!isset($connections[$fd])) continue;
        if ($connections[$fd]['is_host']) {
            $srv->push($fd, json_encode($payload));
        }
    }
}

function pushOne(Server $srv, int $fd, array $payload): void
{
    if ($srv->isConnected($fd)) {
        $srv->push($fd, json_encode($payload));
    }
}

/** 异步 POST 到 Laravel（不阻塞信令循环） */
function httpPostAsync(string $url, array $body): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 2,
        CURLOPT_NOBODY => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
