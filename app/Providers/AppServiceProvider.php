<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use App\Models\Project;
use App\Models\Task;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Services\TrashService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 注册服务层
        $this->app->singleton(\App\Services\ProjectService::class);
        $this->app->singleton(\App\Services\TaskService::class);
        $this->app->singleton(\App\Services\FileService::class);
        $this->app->singleton(\App\Services\DashboardService::class);
        $this->app->singleton(\App\Services\NotificationService::class);

        // 合并 PM 配置文件
        $this->mergeConfigFrom(
            __DIR__.'/../../config/pm.php', 'pm'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 配置默认分页视图为 Bootstrap 5
        \Illuminate\Pagination\Paginator::useBootstrapFive();

        // 注册 Policy
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);

        // 软删除时自动记录「删除人」
        $this->registerTrashDeletingHook();

        // AI 每用户限流（每分钟/每日，管理员可在「AI 设置」调整）
        RateLimiter::for('ai', function ($request) {
            $setting = \App\Models\AiSetting::current();
            $minute = max(1, (int) $setting->rate_limit_minute ?: 30);
            $daily = max(1, (int) $setting->rate_limit_daily ?: 200);

            // 按用户隔离；未登录退化为 IP
            $key = $request->user()?->id ?: 'guest:' . $request->ip();

            return [
                Limit::perMinute($minute)->by($key),
                Limit::perDay($daily)->by($key),
            ];
        });
    }

    /**
     * 为所有纳入回收站的模型注册 deleting 事件：
     * 软删除时把操作人写入 deleted_by，使「谁删的」可被追溯，
     * 供个人回收站按 deleted_by 匹配；彻底删除不记录。
     *
     * 采用事件钩子而非逐个改造删除入口，可覆盖全系统所有软删调用点。
     */
    protected function registerTrashDeletingHook(): void
    {
        foreach (TrashService::TYPES as $config) {
            $config['model']::deleting(function ($model) {
                if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                    return;
                }

                if (!TrashService::hasDeletedByColumn($model)) {
                    return;
                }

                try {
                    $userId = auth()->id();

                    DB::table($model->getTable())
                        ->where($model->getKeyName(), $model->getKey())
                        ->update(['deleted_by' => $userId]);

                    $model->deleted_by = $userId;
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }
    }
}
