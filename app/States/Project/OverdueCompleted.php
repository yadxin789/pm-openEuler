<?php

namespace App\States\Project;

class OverdueCompleted extends ProjectState
{
    public function label(): string
    {
        $project = $this->getModel();
        if ($project && $project->end_date) {
            $endDate = \Carbon\Carbon::parse($project->end_date);
            $now = now();
            $overdueDays = $endDate->diffInDays($now, false);
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