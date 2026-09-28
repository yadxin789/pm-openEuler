<?php

namespace App\Services;

use App\Models\User;
use App\Models\WecomMessageLog;
use Illuminate\Support\Facades\Log;

class WecomNotificationService
{
    protected WecomService $wecom;

    public function __construct()
    {
        $this->wecom = app(WecomService::class);
    }

    /**
     * 向用户推送企业微信通知
     *
     * @param User   $user 接收用户
     * @param string $type 通知类型（对应 wecom.templates 的 key）
     * @param array  $data 模板变量替换数据
     * @param string $url  点击跳转的 URL（默认为系统首页）
     */
    public function send(User $user, string $type, array $data = [], string $url = ''): bool
    {
        // 前置检查
        if (!$this->wecom->isEnabled()) {
            return false;
        }
        if (empty($user->wecom_userid)) {
            return false;
        }
        if (!$user->wecom_notify_enabled) {
            return false;
        }

        // 检查用户个人通知类型开关
        $settings = $this->getUserSettings($user);
        if (!($settings[$type] ?? true)) {
            return false;
        }

        // 获取模板
        $templates = config('wecom.templates', []);
        $template  = $templates[$type] ?? null;
        if (!$template) {
            return false;
        }

        $title       = $this->interpolate($template['title'], $data);
        $description = $this->interpolate($template['description'], $data);
        $btntxt      = $template['btntxt'] ?? '查看详情';
        $url         = $url ?: config('app.url');

        // 发送
        $result = $this->wecom->sendTextCard(
            $user->wecom_userid,
            $title,
            $description,
            $url,
            $btntxt
        );

        // 记录日志
        WecomMessageLog::create([
            'user_id'       => $user->id,
            'msg_type'      => 'textcard',
            'title'         => $title,
            'content'       => $description,
            'response_code' => (string) ($result['errcode'] ?? 'unknown'),
            'response_body' => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);

        $success = ($result['errcode'] ?? -1) === 0;
        if (!$success) {
            Log::warning('企微消息发送失败', [
                'user_id' => $user->id,
                'type'    => $type,
                'resp'    => $result,
            ]);
        }

        return $success;
    }

    /**
     * 获取用户的通知类型开关设置
     */
    protected function getUserSettings(User $user): array
    {
        $raw = $user->wecom_notify_settings;
        if (empty($raw)) {
            return $this->getDefaultSettings();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->getDefaultSettings();
        }
        return $decoded;
    }

    /**
     * 默认通知开关：全部开启
     */
    public function getDefaultSettings(): array
    {
        $templates = config('wecom.templates', []);
        $settings  = [];
        foreach (array_keys($templates) as $key) {
            $settings[$key] = true;
        }
        return $settings;
    }

    /**
     * 模板变量替换
     */
    protected function interpolate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value ?? '', $template);
        }
        return $template;
    }
}
