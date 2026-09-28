<?php

namespace App\States\Project;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class ProjectState extends State
{
    abstract public function label(): string;

    abstract public function color(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(InProgress::class)
            // 进行中可以转换到已完成、已逾期、已暂停
            ->allowTransition(InProgress::class, Completed::class)
            ->allowTransition(InProgress::class, Overdue::class)
            ->allowTransition(InProgress::class, Suspended::class)
            // 已逾期可以转换到已完成、逾期完成、已暂停
            ->allowTransition(Overdue::class, Completed::class)
            ->allowTransition(Overdue::class, OverdueCompleted::class)
            ->allowTransition(Overdue::class, Suspended::class)
            // 已暂停可以转换到进行中、已逾期
            ->allowTransition(Suspended::class, InProgress::class)
            ->allowTransition(Suspended::class, Overdue::class);
    }
}