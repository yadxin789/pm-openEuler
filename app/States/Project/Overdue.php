<?php

namespace App\States\Project;

class Overdue extends ProjectState
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