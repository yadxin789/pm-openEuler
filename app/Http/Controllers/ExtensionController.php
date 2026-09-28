<?php

namespace App\Http\Controllers;

use App\Models\ExtensionApplication;
use App\Services\ExtensionService;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    protected ExtensionService $extensionService;

    public function __construct(ExtensionService $extensionService)
    {
        $this->middleware('auth');
        $this->extensionService = $extensionService;
    }

    /**
     * 禁止对暂停状态项目的任务执行操作
     */
    protected function blockIfProjectInactive(ExtensionApplication $application): void
    {
        $application->loadMissing('task.project');
        $project = $application->task?->project;
        if (!$project) return;

        $projectStatus = get_class($project->status);
        if ($projectStatus === \App\States\Project\Suspended::class) {
            abort(403, '项目已暂停，无法执行任务操作。请等待项目恢复后再试。');
        }
    }

    /**
     * 显示待审批的延期申请列表
     */
    public function index()
    {
        $user = auth()->user();
        
        if ($user->isProjectManager()) {
            // 项目总经理查看所有延期申请（只读监督，不能审批）
            $applications = ExtensionApplication::with('task.project', 'applicant', 'approver')
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            // 项目创建人只能看到自己项目的延期申请
            $applications = ExtensionApplication::where('approver_id', $user->id)
                ->where('status', 'pending')
                ->with('task.project', 'applicant', 'approver')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('extension.index', compact('applications'));
    }

    /**
     * 批准延期申请
     */
    public function approve(ExtensionApplication $application)
    {
        $user = auth()->user();
        $this->blockIfProjectInactive($application);
        if ($application->approver_id != $user->id) {
            abort(403, '您无权审批此申请');
        }

        try {
            $this->extensionService->approve($application);
            return redirect()->back()->with('success', '延期申请已通过');
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * 驳回延期申请
     */
    public function reject(Request $request, ExtensionApplication $application)
    {
        $user = auth()->user();
        $this->blockIfProjectInactive($application);
        if ($application->approver_id != $user->id) {
            abort(403, '您无权审批此申请');
        }

        try {
            $this->extensionService->reject($application, $request->reason);
            return redirect()->back()->with('success', '延期申请已驳回');
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}