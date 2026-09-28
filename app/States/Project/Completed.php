<?php

namespace App\States\Project;

class Completed extends ProjectState
{
    public function label(): string
    {
        return '已完成';
    }

    public function color(): string
    {
        return '#28a745';
    }
}