<?php

namespace App\Http\Controllers;

use App\Models\CollabDocument;
use App\Models\DepartmentFile;
use App\Models\Project;
use App\Models\VoiceParticipant;
use App\Models\VoiceRoom;
use App\Models\VoiceSession;
use App\Services\CollabOfficeService;
use App\Services\VoiceTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class VoiceController extends Controller
{
    /**
     * 严格鉴权：只有项目 participants() 包含的用户才能入会。
     * 管理员/项目经理即使不在 participants 里也一律拒绝。
     */
    private function assertProjectMember(Project $project): void
    {
        $memberIds = $project->participants()->pluck('id');
        abort_unless($memberIds->contains(auth()->id()), 403, '非本项目成员，不能加入电话会议');
    }

    /**
     * 电话会议主页面（标签页入口）。
     */
    public function index(Project $project)
    {
        $this->assertProjectMember($project);

        $room = VoiceRoom::forProject($project);
        $isHost = $project->creator_id === auth()->id();

        // OnlyOffice 支持的格式
        $ooExt = ['docx','doc','xlsx','xls','pptx','ppt','pdf'];

        // 项目文档
        $projectDocs = CollabDocument::where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->whereIn('extension', $ooExt)
            ->orderBy('name')
            ->get(['id', 'name', 'extension'])
            ->map(fn($d) => ['type' => 'project', 'id' => $d->id, 'name' => $d->name, 'ext' => $d->extension]);

        // 资料库（后续再接，department_files 字段名不同）
        // $libDocs = collect();
        $documents = $projectDocs;

        return view('project.voice', [
            'project' => $project,
            'room' => $room,
            'isHost' => $isHost,
            'activeTab' => 'voice',
            'documents' => $documents,
        ]);
    }

    /**
     * 签发 WebSocket 入场 token（短期）。
     */
    public function issueToken(Request $request, Project $project, VoiceTokenService $tokens)
    {
        $this->assertProjectMember($project);
        $room = VoiceRoom::forProject($project);

        return response()->json([
            'token' => $tokens->issue(auth()->id(), $project->id, $room->id),
            'room' => [
                'id' => $room->id,
                'project_id' => $project->id,
                'host_id' => $room->host_id,
                'current_presenter_id' => $room->current_presenter_id,
            ],
            'user' => [
                'id' => auth()->id(),
                'name' => auth()->user()->name,
            ],
            'is_host' => $project->creator_id === auth()->id(),
            'signal_ws' => config('voice.signal_ws'),
            'janus_ws' => config('voice.janus_ws'),
            'stun' => config('voice.stun'),
        ]);
    }

    /**
     * 成员列表（严格 participants，在线状态沿用聊天室口径：5 分钟内活跃）。
     */
    public function members(Project $project)
    {
        $this->assertProjectMember($project);

        $participants = $project->participants();
        $members = $participants->map(function ($u) use ($project) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'avatar' => $u->avatar,
                'is_host' => (int) $u->id === (int) $project->creator_id,
            ];
        });

        return response()->json(['members' => $members]);
    }

    /**
     * 生成某篇文档的只读 OnlyOffice editor config（前端用来初始化 iframe）。
     */
    public function docConfig(Project $project, CollabDocument $doc, CollabOfficeService $office, Request $request)
    {
        $this->assertProjectMember($project);
        abort_unless($doc->project_id === $project->id, 404);

        $canEdit = (bool) $request->query('edit', false);
        $config = $office->buildEditorConfig($doc, auth()->user(), $canEdit);

        return response()->json([
            'server_url' => $office->serverUrl(),
            'config' => $config,
            'download_url' => url('/collab/download/' . $doc->id . '?k=' . urlencode($doc->doc_key)),
        ]);
    }

    /**
     * 上传本地演示文件，创建临时 CollabDocument（7 天后自动清理）。
     */
    public function uploadDemoFile(Project $project, Request $request)
    {
        $this->assertProjectMember($project);
        $allowed = ['docx','xlsx','pptx','pdf','doc','xls','ppt'];
        $data = $request->validate([
            'file' => 'required|file|max:26214400',
        ]);
        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, $allowed)) {
            return response()->json(['error' => '不支持的文件格式，仅支持 docx/xlsx/pptx/pdf'], 422);
        }
        $path = $request->file('file')->store('voice_demo', 'local');
        $doc = CollabDocument::create([
            'project_id' => $project->id,
            'name' => pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME),
            'extension' => $ext,
            'storage_path' => $path,
            'doc_key' => \Illuminate\Support\Str::random(32),
            'size' => $request->file('file')->getSize(),
            'created_by' => auth()->id(),
            'is_temp_demo' => true,
            'expires_at' => Carbon::now()->addDays(7),
        ]);
        return response()->json(['id' => $doc->id]);
    }

    /**
     * Swoole 信令服务回调：成员入会，开启/复用 session 并写 participant 记录。
     */
    public function hookEnter(Request $request)
    {
        // TODO 第 3 步：用共享 secret 校验来源，防止伪造
        $data = $request->validate([
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        $room = VoiceRoom::findOrFail($data['room_id']);
        $room->update(['active' => true]);

        // 找或开 session
        $session = VoiceSession::where('voice_room_id', $room->id)
            ->whereNull('ended_at')
            ->latest('id')
            ->first();
        if (!$session) {
            $session = VoiceSession::create([
                'voice_room_id' => $room->id,
                'started_by' => $room->host_id,
                'started_at' => Carbon::now(),
            ]);
        }

        VoiceParticipant::create([
            'voice_session_id' => $session->id,
            'user_id' => $data['user_id'],
            'joined_at' => Carbon::now(),
        ]);

        return response()->json(['ok' => true, 'session_id' => $session->id]);
    }

    /**
     * Swoole 信令服务回调：成员离会，补 left_at。
     */
    public function hookLeave(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'required|integer',
            'user_id' => 'required|integer',
            'reason' => 'nullable|string|max:30',
        ]);

        $p = VoiceParticipant::where('user_id', $data['user_id'])
            ->whereNull('left_at')
            ->latest('id')
            ->first();
        if ($p) {
            $p->update([
                'left_at' => Carbon::now(),
                'leave_reason' => $data['reason'] ?? 'normal',
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * 会议日志：历史 sessions + 参与人员。
     */
    public function history(Project $project)
    {
        $this->assertProjectMember($project);
        $room = VoiceRoom::forProject($project);

        $sessions = VoiceSession::where('voice_room_id', $room->id)
            ->with(['participants.user:id,name,username'])
            ->orderByDesc('started_at')
            ->limit(20)
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'started_at' => $s->started_at?->toDateTimeString(),
                'ended_at' => $s->ended_at?->toDateTimeString(),
                'participants' => $s->participants->map(fn($p) => [
                    'name' => $p->user?->name,
                    'joined_at' => $p->joined_at?->toDateTimeString(),
                    'left_at' => $p->left_at?->toDateTimeString(),
                    'was_presenter' => $p->was_presenter,
                ]),
            ]);

        return response()->json(['sessions' => $sessions]);
    }
}
