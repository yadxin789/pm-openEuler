<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每天凌晨0点自动刷新项目状态、任务状态、进度和统计数据
Schedule::command('projects:refresh-status')->dailyAt('00:00');
