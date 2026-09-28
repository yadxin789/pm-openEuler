<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectLog;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * 显示项目履历页面
     */
    public function projectHistory(Project $project)
    {
        // 获取项目的所有日志记录
        $logs = ProjectLog::where('project_id', $project->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('history.project-history', compact('project', 'logs'));
    }
}
