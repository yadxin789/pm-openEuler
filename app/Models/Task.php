<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\ModelStates\HasStates;
use App\States\Task\Pending;
use App\States\Task\Overdue;
use App\States\Task\Completed;

class Task extends Model
{
    use HasStates, SoftDeletes;

    protected $fillable = [
        'project_id', 'department_id', 'task_type_id', 'name',
        'executor_id', 'auditor_id', 'start_date', 'end_date',
        'status', 'is_overdue', 'extension_days', 'paused_at', 'completed_at'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_overdue' => 'boolean',
        'status' => \App\States\Task\TaskState::class,
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $stateConfig = [
        'status' => [
            'class' => \App\States\Task\TaskState::class,
            'default' => Pending::class,
        ],
    ];

    /**
     * 记录状态转换日志
     */
    protected static function booted(): void
    {
        static::saved(function (Task $task) {
            if ($task->wasChanged('status')) {
                $oldStatus = $task->getOriginal('status');
                $newStatus = $task->getAttribute('status');

                StatusTransition::log(
                    'task',
                    $task->id,
                    is_string($oldStatus) ? $oldStatus : get_class($oldStatus),
                    is_object($newStatus) ? get_class($newStatus) : $newStatus,
                    auth()->check() ? auth()->id() : null
                );
            }
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function taskType()
    {
        return $this->belongsTo(TaskType::class);
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    public function files()
    {
        return $this->hasMany(File::class);
    }

    public function currentFile()
    {
        return $this->hasOne(File::class)->where('status', 'approved')->latest();
    }

    public function extensionApplications()
    {
        return $this->hasMany(ExtensionApplication::class);
    }

    public function checkOverdue()
    {
        if (!$this->is_overdue && $this->end_date->isPast() && !$this->status->is(Completed::class)) {
            $this->is_overdue = true;
            $this->status->transitionTo(\App\States\Task\Overdue::class);
            $this->save();
            return true;
        }
        return false;
    }

    public function extendDays($days)
    {
        $this->end_date = $this->end_date->addDays($days);
        $this->extension_days += $days;
        $this->is_overdue = false;
        if ($this->status->equals(Overdue::class)) {
            $this->status->transitionTo(Pending::class);
        }
        $this->save();
    }

    // ==================== 统一操作权限判断（单一可信源）====================
    //
    // 冻结规则：只有「项目已暂停(Suspended)」才冻结任务文件的上传/变更/审核。
    // 其余状态（等待中、进行中、已逾期、已驳回、待审核-无待审文件、已完成-无已通过文件）
    // 都必须保证执行人能上传、审核人能审核、有已通过文件时能更新。

    /**
     * 判断是否允许上传文件（仅任务执行人本人可上传）
     * 允许状态：Pending, Rejected, InProgress, Overdue（含项目已逾期）
     * 禁止条件：
     *   1. 项目已暂停(Suspended)
     *   2. 已存在待审核文件（避免重复上传，需先由审核人驳回）
     *   3. 已完成(Completed/OverdueCompleted)且已有已通过文件（改由「更新」流程处理）
     */
    public function canUploadFile(?int $userId = null): bool
    {
        if ($userId === null) {
            $userId = auth()->check() ? auth()->id() : null;
        }
        if (!$userId) return false;
        if (!$this->project) return false;
        if ($userId !== (int)$this->executor_id) return false;
        if ($this->project->isInactive()) return false;

        // 已有待审核文件时不再重复上传（含已跳过截止日期的逾期任务），避免产生多份待审文件
        if ($this->hasPendingFile()) {
            return false;
        }

        // 已完成（含逾期完成）：已有已通过文件时走「更新」流程；
        // 没有已通过文件（异常数据）时仍允许上传，避免任务彻底卡死。
        $finished = [
            \App\States\Task\Completed::class,
            \App\States\Task\OverdueCompleted::class,
        ];
        if (in_array(get_class($this->status), $finished, true)) {
            return !$this->files()->where('status', 'approved')->exists();
        }

        return true;
    }

    /**
     * 判断是否允许申请延期（仅任务执行人本人可申请）
     * 允许状态：Pending, Rejected, InProgress, Overdue（和「上传文件」保持一致）
     * 禁止条件：项目已暂停 / 任务已完成(含逾期完成) / 审核中(ForAudit，已提交审核后不可再申请延期)
     */
    public function canApplyExtension(?int $userId = null): bool
    {
        if ($userId === null) {
            $userId = auth()->check() ? auth()->id() : null;
        }
        if (!$userId) return false;
        if (!$this->project) return false;
        if ($userId !== (int)$this->executor_id) return false;
        if ($this->project->isInactive()) return false;

        $forbidden = [
            \App\States\Task\Completed::class,
            \App\States\Task\OverdueCompleted::class,
            \App\States\Task\ForAudit::class,
        ];
        return !in_array(get_class($this->status), $forbidden, true);
    }

    /**
     * 判断是否允许审核（通过/驳回）—— 审核人用
     * 允许状态：ForAudit（正常待审核）、Overdue（截止日期先到、文件还没审完，仍必须能审）
     * 前提：有待审核文件，且项目未被暂停
     */
    public function canAudit(?int $userId = null): bool
    {
        if ($userId === null) {
            $userId = auth()->check() ? auth()->id() : null;
        }
        if (!$userId) return false;
        if (!$this->project) return false;
        if ($userId === (int)$this->executor_id) return false; // 自己不能审自己
        if ($userId !== (int)$this->auditor_id) return false;
        if ($this->project->isInactive()) return false;

        $auditable = [
            \App\States\Task\ForAudit::class,
            \App\States\Task\Overdue::class,
        ];
        if (!in_array(get_class($this->status), $auditable, true)) return false;

        return $this->hasPendingFile();
    }

    /**
     * 判断是否允许更新已完成的文件（生成新版本并重新提交审核）
     * 允许角色：任务执行人 / 项目创建人 / 管理员
     * 允许状态：Completed、OverdueCompleted（已完成/逾期完成）、Overdue（逾期未完成但已有已通过文件）
     */
    public function canUpdateFile(?int $userId = null): bool
    {
        if ($userId === null) {
            $userId = auth()->check() ? auth()->id() : null;
        }
        if (!$userId) return false;
        if (!$this->project) return false;

        // 管理员判断先取 user 对象（避免在这里面再调用一次 auth()->user() 造成混乱）
        $isAdmin = false;
        $userModel = null;
        if (auth()->check()) {
            $userModel = auth()->user();
            $isAdmin = $userModel && $userModel->isAdmin();
        }
        $isAllowedUser = $userId === (int)$this->executor_id
            || $userId === (int)$this->project->creator_id
            || $isAdmin;
        if (!$isAllowedUser) return false;
        if ($this->project->isInactive()) return false;

        $updatable = [
            \App\States\Task\Completed::class,
            \App\States\Task\OverdueCompleted::class,
            \App\States\Task\Overdue::class,
        ];
        if (!in_array(get_class($this->status), $updatable, true)) return false;

        return $this->files()->where('status', 'approved')->exists();
    }

    /**
     * 是否有待审核文件（供视图快速判断是否显示审核按钮/下载待审文件）
     */
    public function hasPendingFile(): bool
    {
        return $this->files()->where('status', 'pending')->exists();
    }

    /**
     * 获取待审核文件（避免视图里重复查）
     */
    public function pendingFile(): ?File
    {
        return $this->files()->where('status', 'pending')->latest()->first();
    }

    /**
     * 获取已通过的最新文件
     */
    public function approvedFile(): ?File
    {
        return $this->files()->where('status', 'approved')->latest()->first();
    }
}