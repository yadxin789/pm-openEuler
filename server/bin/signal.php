<?php
/**
 * 鐢佃瘽浼氳 Swoole WebSocket 淇′护鏈嶅姟锛堢嫭绔嬪父椹昏繘绋嬶級
 *
 * 鑱岃矗锛? *   - 娴忚鍣?wss 鎻℃墜锛屾牎楠?VoiceTokenService 绛剧殑鐭湡 token
 *   - 鍐呭瓨缁存姢 room_id => [fd => user_info] 鏄犲皠
 *   - 骞挎挱鎴愬憳涓婁笅绾裤€乸resenter 鍙樻洿銆佹枃妗ｅ垏鎹€佺炕椤靛悓姝? *   - presenter_assign/revoke 浜屾鏍￠獙鍙戦€佽€呭繀椤绘槸 host锛堥」鐩垱寤轰汉锛? *   - 杩炴帴鍏抽棴鏃惰嫢鏂嚎鑰呮槸 presenter锛岃嚜鍔ㄥ洖钀界粰 host
 *   - 閫氳繃 HTTP 鍥炶皟 Laravel /voice/hooks/{enter,leave} 鍐欎細璁棩蹇? *
 * 鍚姩锛歱hp /var/www/pm/server/bin/signal.php
 * 鐢?systemd pm-signal.service 鎵樼锛岃繍琛屽睘涓?pmadmin銆? */

use App\Models\VoiceRoom;
use App\Services\VoiceTokenService;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

// ---------- bootstrap Laravel锛堝鐢ㄦā鍨嬩笌 token service锛?----------
$baseDir = dirname(__DIR__, 2);
require $baseDir . '/vendor/autoload.php';
$app = require $baseDir . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tokenSvc = app(VoiceTokenService::class);

// ---------- 鍐呭瓨鐘舵€?----------
// connections[fd] = ['uid'=>int,'pid'=>int,'rid'=>int,'name'=>string,'is_host'=>bool]
$connections = [];
// rooms[rid] = [fd=>true]
$rooms = [];

$host = config('voice.signal_listen', '0.0.0.0');
$port = (int) config('voice.signal_port', 3001);

// Laravel 鍐呯綉鍥炶皟鍦板潃锛圫woole 瀹瑰櫒/杩涚▼璁块棶 PM API锛?$pmInternal = rtrim(env('VOICE_PM_INTERNAL_URL', 'http://127.0.0.1'), '/');

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
        'name' => $user?->name ?? ("鐢ㄦ埛{$uid}"),
        'is_host' => $uid === (int) $room->host_id,
    ];
    $rooms[$rid][$fd] = true;

    // 骞挎挱鎴愬憳鍒楄〃
    broadcastRoom($srv, $rid, $connections, $rooms);

    // HTTP 鍥炶皟 Laravel 鍐欐棩蹇楋紙fire and forget锛?    httpPostAsync($pmInternal . '/voice/hooks/enter', [
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
            // 鍚紬涓炬墜锛氳浆鍙戠粰 host
            sendToHost($srv, $rid, [
                'type' => 'presenter_apply',
                'from_uid' => $me['uid'],
                'from_name' => $me['name'],
            ], $connections, $rooms);
            break;

        case 'presenter_assign':
            // host 鎸囨淳鏌愪汉涓?presenter
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
            // host 鎴?presenter 鍒囨崲婕旂ず鏂囨。
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
            // presenter 缈婚〉/婊氬姩浣嶇疆鍚屾
            $room = VoiceRoom::find($rid);
            if ($me['uid'] !== (int) $room->current_presenter_id) {
                return; // 闈?presenter 闈欓粯涓㈠純
            }
            broadcast($srv, $rid, [
                'type' => 'slide_sync',
                'from_uid' => $me['uid'],
                'page' => $msg['page'] ?? null,
                'scroll' => $msg['scroll'] ?? null,
            ], $connections, $rooms, $fd); // 涓嶅洖缁?presenter 鑷繁
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

    // 鑻ユ柇绾胯€呮槸 presenter锛岃嚜鍔ㄥ洖钀?host
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

// ---------- 杈呭姪鍑芥暟 ----------

function broadcast(Server $srv, int $rid, array $payload, array $connections, array $rooms, ?int $excludeFd = null): void
{
    $data = json_encode($payload);
    foreach ($rooms[$rid] ?? [] as $fd => $_) {
        if ($fd === $excludeFd) continue;
        if ($srv->isEstablished($fd)) {
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
    if ($srv->isEstablished($fd)) {
        $srv->push($fd, json_encode($payload));
    }
}

/** 寮傛 POST 鍒?Laravel锛堜笉闃诲淇′护寰幆锛?*/
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
