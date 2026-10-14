<?php

namespace App\Http\Controllers;

use App\Helpers\OperationLogHelper;
use App\Services\TrashService;
use Illuminate\Http\Request;

/**
 * 个人回收站。
 *
 * 规则：
 * - 每人只能看到并恢复「自己删除的」或「自己创建的」内容；
 * - 管理员只有统一的 /admin/collab-trash 入口，访问本页会被重定向过去；
 * - 个人回收站只提供恢复；彻底删除仍由管理员在统一回收站执行。
 */
class MyTrashController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * 我的回收站列表。
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // 管理员只有统一回收站入口
        if ($user->isAdmin()) {
            return redirect()->route('admin.trash');
        }

        $type = (string) $request->input('type', 'collab-documents');
        if (!isset(TrashService::TYPES[$type])) {
            $type = 'collab-documents';
        }

        $config = TrashService::TYPES[$type];
        $modelClass = $config['model'];

        $items = TrashService::scopeForUser($modelClass::onlyTrashed(), $type, $user->id)
            ->orderByDesc('deleted_at')
            ->paginate(20)
            ->withQueryString();

        // 预先算好展示名称，避免在视图中编写分支逻辑
        $rows = collect($items->items())
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => TrashService::displayName($type, $item),
                'deleted_at' => $item->deleted_at,
                'deleted_by_me' => (int) $item->getAttribute('deleted_by') === (int) $user->id,
            ])
            ->all();

        // 各分类里「我的」待处理数量，用于页签角标
        $counts = [];
        foreach (TrashService::TYPES as $key => $cfg) {
            $counts[$key] = TrashService::scopeForUser($cfg['model']::onlyTrashed(), $key, $user->id)->count();
        }

        $types = TrashService::TYPES;

        return view('my-trash', compact('type', 'types', 'items', 'rows', 'counts'));
    }

    /**
     * 恢复自己回收站中的一条内容。
     */
    public function restore(string $type, int $id)
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return redirect()->route('admin.trash');
        }

        $config = TrashService::typeOrFail($type);

        // 直接在「我的回收站」范围内查找：不属于我的记录一律 404，不泄露他人数据是否存在
        $item = TrashService::scopeForUser($config['model']::onlyTrashed(), $type, $user->id)->find($id);

        abort_if(!$item, 404, '记录不存在，或不在您的回收站中');

        $name = TrashService::displayName($type, $item);
        TrashService::restore($item);

        OperationLogHelper::log('我的回收站', '恢复', "恢复自己回收站中的{$config['label']}「{$name}」（ID:{$id}）");

        return redirect()
            ->route('my-trash', ['type' => $type])
            ->with('success', "已恢复{$config['label']}「{$name}」");
    }
}
