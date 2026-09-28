<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\WecomConfig;

class WecomService
{
    protected ?WecomConfig $config = null;
    protected Client $http;

    public function __construct()
    {
        $this->config = WecomConfig::active();
        $this->http = new Client(array_merge(
            config('wecom.api', []),
            ['base_uri' => config('wecom.api.base_uri', 'https://qyapi.weixin.qq.com')],
        ));
    }

    // ==================== 状态检查 ====================

    public function isEnabled(): bool
    {
        return $this->config !== null
            && $this->config->corp_id
            && $this->config->secret;
    }

    public function getConfig(): ?WecomConfig
    {
        return $this->config;
    }

    // ==================== Access Token ====================

    public function getAccessToken(): string
    {
        if (!$this->config) {
            throw new \RuntimeException('企业微信未配置');
        }

        $cacheKey = 'wecom_access_token_' . $this->config->corp_id;

        return Cache::remember($cacheKey, 7000, function () {
            $resp = $this->apiGet('/cgi-bin/gettoken', [
                'corpid'     => $this->config->corp_id,
                'corpsecret' => $this->config->secret,
            ]);

            if (($resp['errcode'] ?? -1) !== 0) {
                throw new \RuntimeException('获取企微 access_token 失败: ' . ($resp['errmsg'] ?? 'unknown'));
            }

            return $resp['access_token'];
        });
    }

    // ==================== OAuth ====================

    /**
     * 生成企业微信 OAuth 授权 URL
     */
    public function buildOAuthUrl(string $redirectUrl, string $state): string
    {
        $corpId     = $this->config->corp_id;
        $redirect   = urlencode($redirectUrl);
        $scope      = config('wecom.oauth.scopes', 'snsapi_base');

        return "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$corpId}&redirect_uri={$redirect}&response_type=code&scope={$scope}&state={$state}#wechat_redirect";
    }

    /**
     * 通过 OAuth code 获取用户身份信息（获取 UserId）
     */
    public function getUserInfoByCode(string $code): array
    {
        return $this->apiGet('/cgi-bin/auth/getuserinfo', ['code' => $code]);
    }

    /**
     * 通过 UserId 获取用户详细信息
     */
    public function getUserDetail(string $userId): ?array
    {
        $resp = $this->apiGet('/cgi-bin/user/get', ['userid' => $userId]);
        if (($resp['errcode'] ?? -1) !== 0) {
            Log::warning('企微获取用户详情失败', ['userid' => $userId, 'resp' => $resp]);
            return null;
        }
        return $resp;
    }

    // ==================== 消息推送 ====================

    /**
     * 发送 textcard 类型应用消息
     */
    public function sendTextCard(string $touser, string $title, string $description, string $url, string $btntxt = '查看详情'): array
    {
        $payload = [
            'touser'                  => $touser,
            'agentid'                 => (int) $this->config->agent_id,
            'msgtype'                 => 'textcard',
            'textcard'                => [
                'title'       => mb_substr($title, 0, 128),
                'description' => mb_substr($description, 0, 512),
                'url'         => $url,
                'btntxt'      => $btntxt,
            ],
            'enable_id_trans'         => 1,
            'enable_duplicate_check'  => 1,
            'duplicate_check_interval' => 1800,
        ];

        return $this->apiPost('/cgi-bin/message/send', $payload);
    }

    /**
     * 发送纯文本应用消息
     */
    public function sendText(string $touser, string $content): array
    {
        $payload = [
            'touser'                  => $touser,
            'agentid'                 => (int) $this->config->agent_id,
            'msgtype'                 => 'text',
            'text'                    => ['content' => $content],
            'enable_id_trans'         => 1,
            'enable_duplicate_check'  => 1,
        ];

        return $this->apiPost('/cgi-bin/message/send', $payload);
    }

    // ==================== 通讯录 ====================

    /**
     * 获取部门列表
     */
    public function getDepartmentList(?int $parentId = null): array
    {
        $params = [];
        if ($parentId !== null) {
            $params['id'] = $parentId;
        }
        return $this->apiGet('/cgi-bin/department/list', $params);
    }

    /**
     * 获取部门成员（简单信息）
     */
    public function getDepartmentUsers(int $departmentId, bool $fetchChild = true): array
    {
        return $this->apiGet('/cgi-bin/user/simplelist', [
            'department_id' => $departmentId,
            'fetch_child'   => $fetchChild ? 1 : 0,
        ]);
    }

    // ==================== 媒体文件 ====================

    /**
     * 上传临时素材到企业微信
     * @param string $type  素材类型: image/voice/video/file
     * @param string $filePath  文件本地绝对路径
     * @param string|null $filename 上传时使用的文件名
     * @return array 成功返回 ['errcode'=>0, 'media_id'=>'...', 'type'=>'...', 'created_at'=>'...']
     */
    public function uploadMedia(string $type, string $filePath, ?string $filename = null): array
    {
        if (!file_exists($filePath)) {
            return ['errcode' => -1, 'errmsg' => '文件不存在: ' . $filePath];
        }

        $allowed = config('wecom.file.allowed_types', ['image', 'voice', 'video', 'file']);
        if (!in_array($type, $allowed)) {
            return ['errcode' => -1, 'errmsg' => "不支持的素材类型: {$type}"];
        }

        $filename = $filename ?? basename($filePath);

        return $this->apiPostMultipart('/cgi-bin/media/upload', [
            'query' => ['type' => $type],
            'multipart' => [
                [
                    'name'     => 'media',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => $filename,
                ],
            ],
        ]);
    }

    /**
     * 下载临时素材
     * @param string $mediaId  媒体文件 ID
     * @param string $savePath 保存到本地的绝对路径
     * @return array ['success'=>bool, 'path'=>string, 'size'=>int, 'error'=>string|null]
     */
    public function getMedia(string $mediaId, string $savePath): array
    {
        $token = '';
        try {
            $token = $this->getAccessToken();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $saveDir = dirname($savePath);
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }

        try {
            $response = $this->http->get('/cgi-bin/media/get', [
                'query' => ['access_token' => $token, 'media_id' => $mediaId],
                'stream' => true,
            ]);

            $contentType = $response->getHeaderLine('Content-Type');

            // 如果返回 JSON，说明出错
            if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/plain')) {
                $body = json_decode($response->getBody()->getContents(), true);
                $errmsg = $body['errmsg'] ?? '未知下载错误';
                Log::error('企微素材下载失败', ['media_id' => $mediaId, 'error' => $errmsg]);
                return ['success' => false, 'error' => $errmsg];
            }

            // 写入文件
            $handle = fopen($savePath, 'wb');
            $size   = 0;
            while (!$response->getBody()->eof()) {
                $chunk = $response->getBody()->read(8192);
                fwrite($handle, $chunk);
                $size += strlen($chunk);
            }
            fclose($handle);

            Log::info('企微素材下载成功', ['media_id' => $mediaId, 'path' => $savePath, 'size' => $size]);
            return ['success' => true, 'path' => $savePath, 'size' => $size];

        } catch (GuzzleException $e) {
            Log::error('企微素材下载异常', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 发送文件消息
     */
    public function sendFileMessage(string $touser, string $mediaId): array
    {
        $payload = [
            'touser'                  => $touser,
            'agentid'                 => (int) $this->config->agent_id,
            'msgtype'                 => 'file',
            'file'                    => ['media_id' => $mediaId],
            'enable_id_trans'         => 1,
            'enable_duplicate_check'  => 1,
            'duplicate_check_interval' => 1800,
        ];

        return $this->apiPost('/cgi-bin/message/send', $payload);
    }

    /**
     * 发送图片消息
     */
    public function sendImageMessage(string $touser, string $mediaId): array
    {
        $payload = [
            'touser'                  => $touser,
            'agentid'                 => (int) $this->config->agent_id,
            'msgtype'                 => 'image',
            'image'                   => ['media_id' => $mediaId],
            'enable_id_trans'         => 1,
            'enable_duplicate_check'  => 1,
            'duplicate_check_interval' => 1800,
        ];

        return $this->apiPost('/cgi-bin/message/send', $payload);
    }

    // ==================== 底层 HTTP ====================

    protected function apiGet(string $path, array $params = []): array
    {
        $token = '';
        try {
            $token = $this->getAccessToken();
        } catch (\RuntimeException $e) {
            return ['errcode' => -1, 'errmsg' => $e->getMessage()];
        }

        $params['access_token'] = $token;

        try {
            $response = $this->http->get($path, ['query' => $params]);
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException $e) {
            Log::error('企微 API GET 失败', ['path' => $path, 'error' => $e->getMessage()]);
            return ['errcode' => -1, 'errmsg' => $e->getMessage()];
        }
    }

    protected function apiPost(string $path, array $data = []): array
    {
        $token = '';
        try {
            $token = $this->getAccessToken();
        } catch (\RuntimeException $e) {
            return ['errcode' => -1, 'errmsg' => $e->getMessage()];
        }

        $query = ['access_token' => $token];

        try {
            $response = $this->http->post($path, [
                'query' => $query,
                'json'  => $data,
            ]);
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException $e) {
            Log::error('企微 API POST 失败', ['path' => $path, 'error' => $e->getMessage()]);
            return ['errcode' => -1, 'errmsg' => $e->getMessage()];
        }
    }

    /**
     * Multipart POST 请求（用于文件上传等）
     */
    protected function apiPostMultipart(string $path, array $options = []): array
    {
        $token = '';
        try {
            $token = $this->getAccessToken();
        } catch (\RuntimeException $e) {
            return ['errcode' => -1, 'errmsg' => $e->getMessage()];
        }

        // 合并 access_token 到 query
        $options['query'] = array_merge(
            $options['query'] ?? [],
            ['access_token' => $token]
        );

        try {
            $response = $this->http->post($path, $options);
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException $e) {
            Log::error('企微 API Multipart POST 失败', ['path' => $path, 'error' => $e->getMessage()]);
            return ['errcode' => -1, 'errmsg' => $e->getMessage()];
        }
    }
}
