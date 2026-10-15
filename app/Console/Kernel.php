<?php

namespace App\Console;

/**
 * ⚠️ 本文件为历史遗留，已被清空，请勿在此处定义任何调度。
 *
 * 本项目是 Laravel 11+ 结构（由 bootstrap/app.php 驱动），
 * 框架会把 Illuminate\Contracts\Console\Kernel 绑定到框架自带的内核，
 * **不会加载 App\Console\Kernel**，因此写在这里的 schedule() 永远不会执行。
 *
 * 原先这里的 5 个调度因此一直未生效，现已迁移：
 * - 定时提醒（任务到期 / 任务逾期 / 审核超时 / 项目逾期）→ app/Services/ReminderService.php
 * - 全部调度注册统一入口            → routes/console.php
 */
class Kernel
{
    //
}
