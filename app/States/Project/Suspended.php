<?php

namespace App\States\Project;

class Suspended extends ProjectState
{
    public function label(): string
    {
        return '已暂停';
    }

    public function color(): string
    {
        return '#6f42c1';
    }
}