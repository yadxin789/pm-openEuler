<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\ModelStates\HasStates;
use App\States\Project\InProgress;
use App\States\Task\Completed as TaskCompleted;

class Project extends Model
{
    use HasStates, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'version', 'product_name', 'product_model',
        'creator_id', 'start_date', 'end_date', 'status', 'progress',
        'paused_reason', 'paused_at', 'resumed_at', 'total_paused_days'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'progress' => 'decimal:2',
        'status' => \App\States\Project\ProjectState::class,
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    protected $stateConfig = [
        'status' => [
            'class' => \App\States\Project\ProjectState::class,
            'default' => InProgress::class,
        ],
    ];

    /**
     * 记录状态转换日志
     */
    protected static function booted(): void
    {
        static::saved(function (Project $project) {
            if ($project->wasChanged('status')) {
                $oldStatus = $project->getOriginal('status');
                $newStatus = $project->getAttribute('status');
                
                StatusTransition::log(
                    'project',
                    $project->id,
                    is_string($oldStatus) ? $oldStatus : get_class($oldStatus),
                    is_object($newStatus) ? get_class($newStatus) : $newStatus,
                    auth()->check() ? auth()->id() : null
                );
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function logs()
    {
        return $this->hasMany(ProjectLog::class);
    }

    public function calculateProgress()
    {
        $total = $this->tasks()->count();
        $completed = $this->tasks()->whereIn('status', [TaskCompleted::class, \App\States\Task\OverdueCompleted::class])->count();

        $this->progress = $total > 0 ? ($completed / $total) * 100 : 0;
        $this->save();

        // 项目已暂停：冻结状态下不做任何自动状态流转，恢复后按新排期重新计算
        if ($this->status->equals(\App\States\Project\Suspended::class)) {
            return $this->progress;
        }

        // 如果所有任务都已完成，自动将项目状态转为已完成或逾期XX天完成
        if ($total > 0 && $completed == $total) {
            $overdueState = \App\States\Project\Overdue::class;
            if ($this->status->equals($overdueState)) {
                // 项目原本已逾期，现在所有任务完成了 -> 转为逾期XX天完成
                $overdueCompletedState = \App\States\Project\OverdueCompleted::class;
                if (!$this->status->equals($overdueCompletedState)) {
                    try {
                        $this->status->transitionTo($overdueCompletedState);
                        $this->save();
                    } catch (\Exception $e) {
                        \Log::warning("项目状态自动转换失败: {$this->id}", [
                            'error' => $e->getMessage(),
                            'project' => $this->name,
                        ]);
                    }
                }
            } else {
                $completedState = \App\States\Project\Completed::class;
                $overdueCompletedState = \App\States\Project\OverdueCompleted::class;
                if (!$this->status->equals($completedState) && !$this->status->equals($overdueCompletedState)) {
                    try {
                        $this->status->transitionTo($completedState);
                        $this->save();
                    } catch (\Exception $e) {
                        \Log::warning("项目状态自动转换失败: {$this->id}", [
                            'error' => $e->getMessage(),
                            'project' => $this->name,
                        ]);
                    }
                }
            }
        }

        return $this->progress;
    }

    /**
     * 获取该项目实际参与的部门（基于已创建的 tasks 的 department_id）
     */
    public function participatingDepartments()
    {
        return \App\Models\Department::whereIn('id',
            $this->tasks()->pluck('department_id')->unique()
        )->orderBy('sort_order')->get();
    }

    public function getDepartmentProgress($departmentId)
    {
        $tasks = $this->tasks()->where('department_id', $departmentId);
        $total = $tasks->count();
        $completed = $tasks->where('status', TaskCompleted::class)->count();

        return $total > 0 ? ($completed / $total) * 100 : 0;
    }

    public function participants()
    {
        $executors = $this->tasks()->pluck('executor_id');
        $auditors = $this->tasks()->pluck('auditor_id');
        $userIds = $executors->merge($auditors)->push($this->creator_id)->unique();

        return User::whereIn('id', $userIds)->get();
    }

    /**
     * 项目成员（别名方法，用于聊天室功能）
     */
    public function members()
    {
        return $this->participants();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * 判断项目是否处于已暂停状态（该状态下禁止任务操作）
     */
    public function isInactive(): bool
    {
        return $this->status->equals(\App\States\Project\Suspended::class);
    }

    // ==================== 统一操作权限判断（单一可信源）====================

    /**
     * 是否允许暂停项目（只有进行中、逾期可以暂停）
     */
    public function canPause(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if ($userId !== $this->creator_id && !($userId && auth()->check() && auth()->user()->isAdmin())) {
            return false;
        }
        $allowed = [
            \App\States\Project\InProgress::class,
            \App\States\Project\Overdue::class,
        ];
        return in_array(get_class($this->status), $allowed, true);
    }

    /**
     * 是否允许恢复项目（只有已暂停可以恢复）
     */
    public function canResume(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if ($userId !== $this->creator_id && !($userId && auth()->check() && auth()->user()->isAdmin())) {
            return false;
        }
        return $this->status->equals(\App\States\Project\Suspended::class);
    }

    /**
     * 是否允许变更项目（非终态即可，项目创建人或管理员）
     */
    public function canChange(?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();
        if ($userId !== $this->creator_id && !($userId && auth()->check() && auth()->user()->isAdmin())) {
            return false;
        }
        // 已完成/逾期完成的项目不允许变更
        $finished = [
            \App\States\Project\Completed::class,
            \App\States\Project\OverdueCompleted::class,
        ];
        return !in_array(get_class($this->status), $finished, true);
    }
}