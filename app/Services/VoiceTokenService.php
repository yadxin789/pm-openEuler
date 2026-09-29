<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * 电话会议 WebSocket 入场 token 签发与校验。
 * 格式：base64url(payload).base64url(hmac_sha256(payload, APP_KEY))
 * 不引入 JWT 包，Swoole 服务端用同样算法本地校验，不回调 Laravel。
 */
class VoiceTokenService
{
    private function secret(): string
    {
        return (string) config('app.key');
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public function issue(int $userId, int $projectId, int $roomId): string
    {
        $ttl = (int) config('voice.token_ttl', 600);
        $payload = json_encode([
            'uid' => $userId,
            'pid' => $projectId,
            'rid' => $roomId,
            'exp' => Carbon::now()->addSeconds($ttl)->getTimestamp(),
        ]);
        $body = $this->b64url($payload);
        $sig = $this->b64url(hash_hmac('sha256', $body, $this->secret(), true));
        return $body . '.' . $sig;
    }

    /**
     * 校验 token，返回 payload 数组；失败返回 null。
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        $expected = $this->b64url(hash_hmac('sha256', $body, $this->secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode($this->b64urlDecode($body), true);
        if (!is_array($payload) || !isset($payload['uid'], $payload['pid'], $payload['rid'], $payload['exp'])) {
            return null;
        }
        if (Carbon::now()->getTimestamp() > $payload['exp']) {
            return null;
        }
        return $payload;
    }
}
