<?php

namespace App\States\Task;

class InProgress extends TaskState
{
    public function label(): string
    {
        return '进行中';
    }

    public function color(): string
    {
        return '#17a2b8';
    }
}