<?php

namespace App\Http\Controllers;

use App\Helpers\OperationLogHelper;
use App\Models\CollabDocument;
use App\Models\DepartmentFile;
use App\Models\File;
use App\Models\Knowledge;
use App\Models\KnowledgeDoc;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\User;
use App\Models\WecomFile;
use App\Services\TrashService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 管理员回收站。
 *
 * 权限约定（全系统统一）：
 * - 内容创建人 / 项目创建人：只能"软删除"（把内容放进回收站）；
 * - 管理员：可查看回收站、恢复、彻底删除；
 * - 彻底删除会同时清理物理文件，不可逆。
 */
class AdminTrashController extends Controller
{
    /**
     * 回收站分类：type => [模型, 显示名, 图标, 取名字段]
     */
    public const TYPES = [
        'collab-documents'  => ['model' => CollabDocument::class,  'label' => '协作文档', 'icon' => 'fa-file-text-o', 'field' => 'name'],
        'projects'          => ['model' => Project::class,         'label' => '项目',     'icon' => 'fa-folder-o',    'field' => 'name'],
        'tasks'             => ['model' => Task::class,            'label' => '任务',     'icon' => 'fa-tasks',       'field' => 'name'],
        'files'             => ['model' => File::class,            'label' => '任务文件', 'icon' => 'fa-paperclip',   'field' => 'original_name'],
        'department-files'  => ['model' => DepartmentFile::class,  'label' => '资料文件', 'icon' => 'fa-folder-open', 'field' => 'original_name'],
        'messages'          => ['model' => Message::class,         'label' => '聊天记录', 'icon' => 'fa-comments',    'field' => 'content'],
        'wecom-files'       => ['model' => WecomFile::class,       'label' => '企微文件', 'icon' => 'fa-inbox',       'field' => 'original_name'],
        'notifications'     => ['model' => Notification::class,    'label' => '通知',     'icon' => 'fa-bell',        'field' => 'title'],
        'knowledges'        => ['model' => Knowledge::class,       'label' => '知识库',   'icon' => 'fa-book',        'field' => 'title'],
        'knowledge-docs'    => ['model' => KnowledgeDoc::class,    'label' => '知识文档', 'icon' => 'fa-file-text-o', 'field' => 'title'],
        'project-templates' => ['model' => ProjectTemplate::class, 'label' => '项目模板', 'icon' => 'fa-clone',       'field' => 'name'],
    ];

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if ($user && $user->isAdmin()) {
                return $next($request);
            }
            abort(403, '只有管理员可以访问回收站');
        });
    }

    /**
     * 回收站列表（按分类切换）。
     */
    public function index(Request $request)
    {
        $type = (string) $request->input('type', 'collab-documents');
        if (!isset(self::TYPES[$type])) {
            $type = 'collab-documents';
        }

        $config = self::TYPES[$type];
        $modelClass = $config['model'];

        $items = $modelClass::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->paginate(20)
            ->withQueryString();

        // 预先算好展示名称，避免在视图中编写分支逻辑。
        $rows = collect($items->items())
            ->map(fn (Model $item) => [
                'id' => $item->id,
                'name' => $this->displayName($type, $item, $config['field']),
                'deleted_at' => $item->deleted_at,
            ])
            ->all();

        // 各分类的待处理数量，用于页签角标。
        $counts = [];
        foreach (self::TYPES as $key => $cfg) {
            $counts[$key] = $cfg['model']::onlyTrashed()->count();
        }
        $counts['users'] = User::onlyTrashed()->count();

        $types = self::TYPES;

        return view('admin.trash', compact('type', 'types', 'items', 'rows', 'counts'));
    }

    /**
     * 恢复软删除记录。
     */
    public function restore(string $type, int $id)
    {
        $config = $this->resolveType($type);
        $item = $config['model']::onlyTrashed()->findOrFail($id);

        $name = $this->displayName($type, $item, $config['field']);
        TrashService::restore($item);

        OperationLogHelper::log('回收站', '恢复', "管理员恢复{$config['label']}「{$name}」（ID:{$id}）");

        return redirect()
            ->route('admin.trash', ['type' => $type])
            ->with('success', "已恢复{$config['label']}「{$name}」");
    }

    /**
     * 彻底删除（不可逆，同时清理物理文件）。仅管理员可用。
     */
    public function forceDelete(string $type, int $id)
    {
        $config = $this->resolveType($type);
        $item = $config['model']::onlyTrashed()->findOrFail($id);

        $name = $this->displayName($type, $item, $config['field']);
        TrashService::forceDelete($item);

        OperationLogHelper::log('回收站', '彻底删除', "管理员彻底删除{$config['label']}「{$name}」（ID:{$id}），物理文件已一并清理");

        return redirect()
            ->route('admin.trash', ['type' => $type])
            ->with('success', "已彻底删除{$config['label']}「{$name}」");
    }

    /**
     * 校验分类是否合法。
     */
    protected function resolveType(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404, '未知的回收站分类');

        return self::TYPES[$type];
    }

    /**
     * 生成记录在回收站中的展示名称。
     */
    protected function displayName(string $type, Model $model, string $field): string
    {
        if ($type === 'collab-documents') {
            $ext = !empty($model->extension) ? '.' . $model->extension : '';

            return Str::limit(($model->name ?? '未命名') . $ext, 60);
        }

        if ($type === 'messages') {
            $text = trim((string) ($model->content ?? ''));
            if ($text === '') {
                $fileName = (string) ($model->file_name ?? '');
                $text = $fileName !== '' ? '[文件] ' . $fileName : '（空消息）';
            }

            return Str::limit($text, 60);
        }

        return Str::limit((string) ($model->getAttribute($field) ?? '未命名'), 60);
    }
}
