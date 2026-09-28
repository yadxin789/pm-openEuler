<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Project;
use App\Models\Task;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;

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
    }
}
