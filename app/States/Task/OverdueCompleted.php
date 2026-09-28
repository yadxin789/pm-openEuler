<?php

namespace App\States\Task;

class OverdueCompleted extends TaskState
{
    public function label(): string
    {
        $task = $this->getModel();
        if ($task && $task->end_date) {
            $endDate = \Carbon\Carbon::parse($task->end_date);
            $completedAt = $task->completed_at 
                ? \Carbon\Carbon::parse($task->completed_at) 
                : now();
            $overdueDays = $endDate->diffInDays($completedAt, false);
            if ($overdueDays > 0) {
                return '逾期' . $overdueDays . '天完成';
            }
        }
        return '逾期完成';
    }

    public function color(): string
    {
        return '#fd7e14';
    }
}
