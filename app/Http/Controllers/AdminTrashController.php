<?php

namespace App\Http\Controllers;

use App\Helpers\OperationLogHelper;
use App\Models\User;
use App\Services\TrashService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
     * 回收站分类统一维护在 TrashService::TYPES（管理员回收站与个人回收站共用）。
     */
    public const TYPES = TrashService::TYPES;

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
        // 深度删除：容器型内容（项目/任务/部门/模板/知识库）先逐条清理子记录与物理文件，
        // 再删除父记录，避免数据库 CASCADE 硬删子记录时留下孤儿文件。
        TrashService::forceDeleteDeep($item);

        OperationLogHelper::log('回收站', '彻底删除', "管理员彻底删除{$config['label']}「{$name}」（ID:{$id}），物理文件已一并清理");

        return redirect()
            ->route('admin.trash', ['type' => $type])
            ->with('success', "已彻底删除{$config['label']}「{$name}」");
    }

    /**
     * 批量恢复：只恢复勾选的记录，不影响其他内容。
     */
    public function restoreBatch(Request $request, string $type)
    {
        $config = $this->resolveType($type);

        $ids = $this->selectedIds($request);
        if (empty($ids)) {
            return redirect()
                ->route('admin.trash', ['type' => $type])
                ->with('error', '请至少勾选一条记录');
        }

        $items = $config['model']::onlyTrashed()->whereIn('id', $ids)->orderBy('id')->get();
        $names = [];

        foreach ($items as $item) {
            $names[] = $this->displayName($type, $item, $config['field']);
            TrashService::restore($item);
        }

        if (!empty($names)) {
            OperationLogHelper::log('回收站', '批量恢复', sprintf(
                '管理员批量恢复%s %d 条：%s',
                $config['label'],
                count($names),
                $this->summarizeNames($names)
            ));
        }

        return redirect()
            ->route('admin.trash', ['type' => $type])
            ->with('success', sprintf(
                '已恢复 %d 条%s%s',
                count($names),
                $config['label'],
                count($names) < count($ids) ? '（部分记录已不存在，已跳过）' : ''
            ));
    }

    /**
     * 批量彻底删除（不可逆，物理文件一并清理）。仅管理员可用。
     */
    public function forceDeleteBatch(Request $request, string $type)
    {
        $config = $this->resolveType($type);

        $ids = $this->selectedIds($request);
        if (empty($ids)) {
            return redirect()
                ->route('admin.trash', ['type' => $type])
                ->with('error', '请至少勾选一条记录');
        }

        $items = $config['model']::onlyTrashed()->whereIn('id', $ids)->orderBy('id')->get();
        $names = [];

        foreach ($items as $item) {
            $names[] = $this->displayName($type, $item, $config['field']);
            TrashService::forceDeleteDeep($item);
        }

        if (!empty($names)) {
            OperationLogHelper::log('回收站', '批量彻底删除', sprintf(
                '管理员批量彻底删除%s %d 条：%s，物理文件已一并清理',
                $config['label'],
                count($names),
                $this->summarizeNames($names)
            ));
        }

        return redirect()
            ->route('admin.trash', ['type' => $type])
            ->with('success', sprintf(
                '已彻底删除 %d 条%s%s',
                count($names),
                $config['label'],
                count($names) < count($ids) ? '（部分记录已不存在，已跳过）' : ''
            ));
    }

    /**
     * 取出批量提交勾选的 ID：去重、只保留正整数，单次最多 200 条。
     */
    protected function selectedIds(Request $request): array
    {
        $ids = [];

        foreach ((array) $request->input('ids', []) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_slice(array_values($ids), 0, 200);
    }

    /**
     * 生成操作日志里的名称摘要（最多列 5 条，其余折叠为条数）。
     */
    protected function summarizeNames(array $names): string
    {
        $head = array_slice($names, 0, 5);
        $more = count($names) - count($head);

        return implode('、', $head) . ($more > 0 ? " 等 {$more} 条" : '');
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
     * 生成记录在回收站中的展示名称（统一实现见 TrashService::displayName）。
     */
    protected function displayName(string $type, Model $model, ?string $field = null): string
    {
        return TrashService::displayName($type, $model);
    }
}
