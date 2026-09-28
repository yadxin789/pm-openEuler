<?php

namespace App\States\Project;

class NotStarted extends ProjectState
{
    public function label(): string
    {
        return '未启动';
    }

    public function color(): string
    {
        return '#6c757d';
    }
}