<?php

namespace App\Http\Controllers;

use App\Models\CollabDocument;
use App\Models\Project;
use App\Services\CollabOfficeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CollabDocumentController extends Controller
{
    /**
     * 每个协作文档最多保留的历史版本快照数量，超出部分在保存时自动清理。
     */
    private const MAX_HISTORY_VERSIONS = 2;

    public function __construct()
    {
        $this->middleware('auth')->except(['download', 'callback']);
    }

    /**
     * 协同办公页：项目下协作文档列表
     */
    public function index(Project $project, CollabOfficeService $office)
    {
        Gate::authorize('view', $project);

        $documents = CollabDocument::with('creator', 'updater')
            ->where('project_id', $project->id)
            ->orderByDesc('updated_at')
            ->get();

        return view('collab.index', compact('project', 'documents'));
    }

    /**
     * 新建文档页（内置模板 / 上传）
     */
    public function create(Project $project)
    {
        Gate::authorize('view', $project);
        return view('collab.create', compact('project'));
    }

    /**
     * 保存新文档（模板新建 或 上传）
     */
    public function store(Project $project, Request $request, CollabOfficeService $office)
    {
        Gate::authorize('view', $project);

        $office->ensureTemplates();

        $mode = $request->input('mode', 'template');
        $storagePath = null;
        $extension = null;
        $name = null;

        if ($mode === 'upload') {
            $request->validate([
                'name' => 'required|string|max:80',
                'file' => 'required|file|max:20480',
            ], [
                'name.required' => '请输入文档名称',
                'file.required' => '请选择要上传的文档',
                'file.max' => '文档不能超过 20MB',
            ]);

            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
                throw ValidationException::withMessages([
                    'file' => '仅支持 docx / xlsx / pptx 格式',
                ]);
            }
            $name = trim($request->name);
            $relative = 'collab/' . $project->id . '/' . uniqid('doc_', true) . '.' . $extension;
            $file->storeAs(dirname($relative), basename($relative), 'file_repository');
            $storagePath = $relative;
        } else {
            $request->validate([
                'name' => 'required|string|max:80',
                'extension' => 'required|in:docx,xlsx,pptx',
            ], [
                'name.required' => '请输入文档名称',
                'extension.required' => '请选择文档类型',
            ]);

            $extension = $request->extension;
            $name = trim($request->name);
            $relative = 'collab/' . $project->id . '/' . uniqid('doc_', true) . '.' . $extension;
            $templatePath = config('collab.template_dir') . '/template.' . $extension;
            $templateContent = Storage::disk('local')->get($templatePath);
            Storage::disk('file_repository')->put($relative, $templateContent);
            $storagePath = $relative;
        }

        $doc = CollabDocument::create([
            'project_id' => $project->id,
            'name' => $name,
            'extension' => $extension,
            'storage_path' => $storagePath,
            'doc_key' => md5(uniqid('key_', true)),
            'size' => Storage::disk('file_repository')->size($storagePath),
            'version' => 1,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('project.collab.open', ['project' => $project, 'doc' => $doc])
            ->with('success', '文档创建成功，已进入协同编辑');
    }

    /**
     * 打开协同编辑器（iframe 嵌入 OnlyOffice）
     */
    public function open(Project $project, CollabDocument $doc, Request $request, CollabOfficeService $office)
    {
        Gate::authorize('view', $project);

        abort_unless($doc->project_id === $project->id, 404);

        // 每次打开刷新 key，保证 OnlyOffice 获取最新内容
        $doc->refreshDocKey();
        $doc->save();

        $canEdit = true; // 项目参与者即可编辑（已通过 view 授权）
        $config = $office->buildEditorConfig($doc, auth()->user(), $canEdit);

        return view('collab.open', compact('project', 'doc', 'config'));
    }

    /**
     * OnlyOffice 服务器下载文档（无登录态，doc_key 校验）
     */
    public function download(CollabDocument $doc, Request $request)
    {
        abort_unless($request->query('k') === $doc->doc_key, 403);

        if (!Storage::disk('file_repository')->exists($doc->storage_path)) {
            abort(404);
        }

        return Storage::disk('file_repository')->download($doc->storage_path, $doc->name . '.' . $doc->extension);
    }

    /**
     * OnlyOffice 保存回调（无登录态，doc_key 校验）
     * 协议：status=2 已就绪保存 / 6 强制保存；body 含 url 指向最新文件
     */
    public function callback(CollabDocument $doc, Request $request)
    {
        $payload = $request->json()->all();

        if (($payload['key'] ?? null) !== $doc->doc_key) {
            return response()->json(['error' => 1, 'message' => 'key 不匹配']);
        }

        $status = (int) ($payload['status'] ?? 0);

        if (in_array($status, [2, 6], true) && !empty($payload['url'])) {
            // 备份当前版本为历史快照，并清理超出保留数量的旧版本
            if (Storage::disk('file_repository')->exists($doc->storage_path)) {
                $historyPath = 'collab/' . $doc->project_id . '/history/' . $doc->id . '_v' . $doc->version . '.' . $doc->extension;
                Storage::disk('file_repository')->copy($doc->storage_path, $historyPath);
                $this->pruneHistory($doc);
            }

            // 从 OnlyOffice 临时地址下载最新文件
            $content = $this->fetchUrl((string) $payload['url']);
            if ($content !== null) {
                Storage::disk('file_repository')->put($doc->storage_path, $content);
                $doc->size = Storage::disk('file_repository')->size($doc->storage_path);
                $doc->version = $doc->version + 1;
                $doc->save();
            }
        }

        return response()->json(['error' => 0]);
    }

    /**
     * 只保留该文档最近 MAX_HISTORY_VERSIONS 个历史版本快照，超出部分自动清理。
     * 命名规则：collab/{projectId}/history/{docId}_v{version}.{ext}，版本号越大越新。
     */
    private function pruneHistory(CollabDocument $doc): void
    {
        $disk = Storage::disk('file_repository');
        $dir = 'collab/' . $doc->project_id . '/history';

        if (!$disk->exists($dir)) {
            return;
        }

        $prefix = $doc->id . '_v';
        $snapshots = [];

        foreach ($disk->files($dir) as $file) {
            $name = basename($file);
            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            $version = (int) explode('.', substr($name, strlen($prefix)))[0];
            if ($version > 0) {
                $snapshots[$version] = $file;
            }
        }

        if (count($snapshots) <= self::MAX_HISTORY_VERSIONS) {
            return;
        }

        // 版本号由新到旧排序，删除超出保留数量的旧快照（仅本文件，不影响同项目其他文档）
        krsort($snapshots);
        foreach (array_slice($snapshots, self::MAX_HISTORY_VERSIONS) as $obsolete) {
            $disk->delete($obsolete);
        }
    }

    /**
     * 删除协作文档（创建人/项目创建人/管理员）
     */
    public function destroy(Project $project, CollabDocument $doc, Request $request)
    {
        $user = auth()->user();
        abort_unless($doc->project_id === $project->id, 404);

        $canDelete = $user->isAdmin()
            || $user->id === $project->creator_id
            || $user->id === $doc->created_by;

        abort_unless($canDelete, 403);

        // 软删除：只标记删除时间，保留物理文件，管理员可在回收站恢复或彻底删除。
        $doc->delete();

        return redirect()
            ->route('project.collab.index', $project)
            ->with('success', '文档已移入回收站，可由管理员恢复');
    }

    private function fetchUrl(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($code >= 200 && $code < 300 && is_string($body)) ? $body : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
