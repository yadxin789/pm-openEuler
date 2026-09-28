<?php

namespace App\Http\Controllers;

use App\Models\WecomConfig;
use App\Models\WecomFile;
use App\Models\User;
use App\Services\WecomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WecomCallbackController extends Controller
{
    protected WecomService $wecom;

    public function __construct(WecomService $wecom)
    {
        $this->wecom = $wecom;
    }

    /**
     * 企业微信事件回调入口
     */
    public function handle(Request $request)
    {
        $config = WecomConfig::active();

        if (!$config) {
            return response('未配置', 400);
        }

        // ------ URL 验证（首次配置回调 URL 时） ------
        $echostr = $request->input('echostr');
        if ($echostr) {
            $decrypted = $this->verifyUrl($config, $request);
            if ($decrypted === false) {
                return response('验证失败', 403);
            }
            return response($decrypted, 200, ['Content-Type' => 'text/plain']);
        }

        // ------ 事件/消息处理 ------
        $body = $request->getContent();
        $msgSignature = $request->input('msg_signature');
        $timestamp    = $request->input('timestamp');
        $nonce        = $request->input('nonce');

        Log::info('企微回调原始消息', ['body' => $body]);

        if (empty($body)) {
            return response('success');
        }

        // 解密消息体
        $decryptedXml = $this->decryptMsg($config, $body, $msgSignature, $timestamp, $nonce);
        if ($decryptedXml === false) {
            Log::warning('企微消息解密失败');
            return response('success');
        }

        Log::info('企微解密后消息', ['xml' => $decryptedXml]);

        // 解析 XML
        $msg = $this->parseXml($decryptedXml);
        if (!$msg) {
            Log::warning('企微消息 XML 解析失败');
            return response('success');
        }

        $msgType = strtolower($msg['MsgType'] ?? '');

        // 处理文件/图片/语音/视频消息
        if (in_array($msgType, ['image', 'file', 'voice', 'video'])) {
            $this->handleMediaMessage($config, $msg, $msgType);
        } elseif ($msgType === 'text') {
            $this->handleTextMessage($config, $msg);
        } elseif ($msgType === 'event') {
            Log::info('企微事件', ['event' => $msg['Event'] ?? 'unknown', 'msg' => $msg]);
        }

        return response('success');
    }

    // ========== 媒体消息处理 ==========

    /**
     * 处理图片/文件/语音/视频消息
     */
    protected function handleMediaMessage(WecomConfig $config, array $msg, string $msgType): void
    {
        $mediaId  = $msg['MediaId'] ?? '';
        $fromUser = $msg['FromUserName'] ?? '';
        $fileName = $msg['FileName'] ?? ($msgType . '_' . date('YmdHis'));
        $picUrl   = $msg['PicUrl'] ?? null;

        if (empty($mediaId)) {
            Log::warning('企微媒体消息缺少 MediaId');
            return;
        }

        // 查找对应的本地用户
        $user = User::where('wecom_userid', $fromUser)->first();

        // 生成存储路径
        $ext      = pathinfo($fileName, PATHINFO_EXTENSION) ?: $this->guessExt($msgType);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $stored   = date('Ymd/His_') . Str::slug($baseName) . '.' . $ext;

        $disk = Storage::disk('wecom_files');
        $savePath = $disk->path($stored);

        // 下载媒体文件
        $result = $this->wecom->getMedia($mediaId, $savePath);

        if (!$result['success']) {
            Log::error('企微媒体下载失败', [
                'media_id'  => $mediaId,
                'msg_type'  => $msgType,
                'error'     => $result['error'] ?? 'unknown',
            ]);

            // 即使下载失败也回复用户
            if ($user) {
                $this->wecom->sendText($fromUser, "文件「{$fileName}」接收失败，请重试");
            }
            return;
        }

        // 创建 WecomFile 记录
        $wecomFile = WecomFile::create([
            'user_id'       => $user?->id,
            'media_id'      => $mediaId,
            'msg_type'      => $msgType,
            'original_name' => $fileName,
            'stored_name'   => $stored,
            'file_path'     => $stored,
            'file_size'     => $result['size'],
            'mime_type'     => $msgType === 'image' ? 'image/jpeg' : null,
            'status'        => 'pending',
            'callback_data' => $msg,
        ]);

        Log::info('企微文件已接收', [
            'id'            => $wecomFile->id,
            'media_id'      => $mediaId,
            'from_user'     => $fromUser,
            'original_name' => $fileName,
            'size'          => $result['size'],
        ]);

        // 回复确认消息
        if ($user) {
            $this->wecom->sendText(
                $fromUser,
                "已收到您发送的「{$fileName}」（{$wecomFile->formatted_size}）\n"
                . "文件已存入系统收件箱，请前往 PM 系统查看处理"
            );
        }
    }

    /**
     * 处理文本消息
     */
    protected function handleTextMessage(WecomConfig $config, array $msg): void
    {
        $fromUser = $msg['FromUserName'] ?? '';
        $content  = $msg['Content'] ?? '';

        Log::info('企微文本消息', ['from' => $fromUser, 'content' => $content]);

        // 可扩展：支持通过文本指令操作
        // 例如回复 "help" 返回使用指南
    }

    // ========== 加解密工具 ==========

    /**
     * URL 验证 — 解密 echostr
     */
    protected function verifyUrl($config, Request $request)
    {
        $msgSignature = $request->input('msg_signature');
        $timestamp    = $request->input('timestamp');
        $nonce        = $request->input('nonce');
        $echostr      = $request->input('echostr');

        if (!$msgSignature || !$timestamp || !$nonce || !$echostr) {
            return false;
        }

        $decrypted = $this->aesDecrypt($config, $echostr);
        return $decrypted;
    }

    /**
     * 解密回调消息体
     * @return string|false 解密后的 XML 字符串
     */
    protected function decryptMsg(WecomConfig $config, string $xmlBody, ?string $msgSignature, ?string $timestamp, ?string $nonce)
    {
        // 解析 <Encrypt> 节点
        $xml = simplexml_load_string($xmlBody);
        if (!$xml || !isset($xml->Encrypt)) {
            Log::warning('企微消息缺少 Encrypt 节点', ['body' => $xmlBody]);
            return false;
        }

        $encrypt = (string) $xml->Encrypt;

        // 如果提供了签名参数，校验签名
        if ($msgSignature && $timestamp && $nonce && $config->token) {
            if (!$this->checkSignature($config, $msgSignature, $timestamp, $nonce, $encrypt)) {
                Log::warning('企微消息签名校验失败');
                return false;
            }
        }

        return $this->aesDecrypt($config, $encrypt);
    }

    /**
     * AES-256-CBC 解密
     */
    protected function aesDecrypt(WecomConfig $config, string $encryptedBase64): string|false
    {
        $key  = base64_decode($config->encoding_aes_key . '=');
        $data = base64_decode($encryptedBase64);

        if (!$key || !$data) {
            return false;
        }

        $iv      = substr($key, 0, 16);
        $decoded = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($decoded === false) {
            return false;
        }

        // 去掉前 16 字节随机串 + 4 字节长度 = 实际内容，再截掉末尾的 CorpID
        $content = substr($decoded, 16);
        $len     = unpack('N', substr($content, 0, 4))[1];
        return substr($content, 4, $len);
    }

    /**
     * 校验消息签名
     */
    protected function checkSignature(WecomConfig $config, string $sig, string $timestamp, string $nonce, string $encrypt): bool
    {
        $arr   = [$config->token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);
        $hash  = sha1(implode('', $arr));
        return $hash === $sig;
    }

    /**
     * 解析 XML 为数组
     */
    protected function parseXml(string $xml): ?array
    {
        $parsed = simplexml_load_string($xml);
        if (!$parsed) {
            return null;
        }

        $result = [];
        foreach ($parsed->children() as $child) {
            $result[$child->getName()] = (string) $child;
        }
        return $result;
    }

    /**
     * 根据消息类型推测扩展名
     */
    protected function guessExt(string $msgType): string
    {
        return [
            'image' => 'jpg',
            'voice' => 'amr',
            'video' => 'mp4',
            'file'  => 'bin',
        ][$msgType] ?? 'bin';
    }
}
