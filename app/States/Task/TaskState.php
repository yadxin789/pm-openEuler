<?php

namespace App\States\Task;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class TaskState extends State
{
    abstract public function label(): string;

    abstract public function color(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            // 等待中可以转换到进行中、待审核、已完成
            ->allowTransition(Pending::class, InProgress::class)
            ->allowTransition(Pending::class, ForAudit::class)
            ->allowTransition(Pending::class, Completed::class)
            // 进行中可以转换到待审核、已完成、已逾期
            ->allowTransition(InProgress::class, ForAudit::class)
            ->allowTransition(InProgress::class, Completed::class)
            ->allowTransition(InProgress::class, Overdue::class)
            // 待审核可以转换到已完成、已驳回
            ->allowTransition(ForAudit::class, Completed::class)
            ->allowTransition(ForAudit::class, Rejected::class)
            // 等待中、待审核、已驳回 可以转换到已逾期（截止日期过期）
            ->allowTransition(Pending::class, Overdue::class)
            ->allowTransition(ForAudit::class, Overdue::class)
            ->allowTransition(Rejected::class, Overdue::class)
            // 已驳回可以转换到等待中、待审核（重新上传文件）
            ->allowTransition(Rejected::class, Pending::class)
            ->allowTransition(Rejected::class, ForAudit::class)
            // 已逾期可以转换到等待中（延期）、进行中（项目恢复/重算日期）、待审核（上传文件）、完成、逾期完成（审核通过）
            ->allowTransition(Overdue::class, Pending::class)
            ->allowTransition(Overdue::class, InProgress::class)
            ->allowTransition(Overdue::class, ForAudit::class)
            ->allowTransition(Overdue::class, Completed::class)
            ->allowTransition(Overdue::class, OverdueCompleted::class)
            // 已完成、逾期完成 可以转换到待审核（更新文件后重新审核）
            ->allowTransition(Completed::class, ForAudit::class)
            ->allowTransition(OverdueCompleted::class, ForAudit::class);
    }
}