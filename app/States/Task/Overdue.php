<?php

namespace App\States\Task;

class Overdue extends TaskState
{
    public function label(): string
    {
        return '已逾期';
    }

    public function color(): string
    {
        return '#dc3545';
    }
}