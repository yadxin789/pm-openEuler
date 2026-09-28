<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusTransition extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'from_status',
        'to_status',
        'user_id',
        'note',
    ];

    /**
     * 记录状态转换
     */
    public static function log(string $modelType, int $modelId, string $from, string $to, ?int $userId = null, ?string $note = null): self
    {
        return self::create([
            'model_type' => $modelType,
            'model_id' => $modelId,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId ?? (auth()->check() ? auth()->id() : null),
            'note' => $note,
        ]);
    }

    /**
     * 获取模型的状态变更历史
     */
    public static function forModel(string $modelType, int $modelId)
    {
        return self::where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
