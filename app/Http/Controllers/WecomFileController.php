<?php

namespace App\Http\Controllers;

use App\Models\WecomFile;
use App\Services\WecomService;
use Illuminate\Http\Request;

class WecomFileController extends Controller
{
    protected WecomService $wecom;

    public function __construct(WecomService $wecom)
    {
        $this->wecom = $wecom;
    }

    /**
     * 企微文件收件箱 — 列表页
     */
    public function index(Request $request)
    {
        $query = WecomFile::with('user')
            ->orderByDesc('created_at');

        // 筛选
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('msg_type')) {
            $query->where('msg_type', $request->input('msg_type'));
        }

        $files    = $query->paginate(20);
        $statuses = ['pending' => '待处理', 'archived' => '已归档', 'linked' => '已关联'];
        $types    = ['image' => '图片', 'file' => '文件', 'voice' => '语音', 'video' => '视频'];

        $counts = WecomFile::selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();
        $counts['total'] = array_sum($counts);

        return view('wecom.files.index', compact('files', 'statuses', 'types', 'counts'));
    }

    /**
     * 预览文件
     */
    public function preview(WecomFile $file)
    {
        $fullPath = $file->getFullPath();

        if (!file_exists($fullPath)) {
            abort(404, '文件不存在');
        }

        $mime = $file->mime_type ?: mime_content_type($fullPath);
        return response()->file($fullPath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $file->original_name . '"',
        ]);
    }

    /**
     * 下载文件
     */
    public function download(WecomFile $file)
    {
        $fullPath = $file->getFullPath();

        if (!file_exists($fullPath)) {
            abort(404, '文件不存在');
        }

        return response()->download($fullPath, $file->original_name);
    }

    /**
     * 归档文件
     */
    public function archive(WecomFile $file)
    {
        $file->update(['status' => 'archived']);
        return back()->with('success', '文件已归档');
    }

    /**
     * 删除文件（软删除，进入回收站）
     *
     * 仅管理员或文件归属人可删除；物理文件保留，等待管理员在回收站彻底删除。
     */
    public function destroy(WecomFile $file)
    {
        $user = auth()->user();
        abort_unless($user && ($user->isAdmin() || $file->user_id === $user->id), 403, '您没有权限删除该文件');

        $file->delete();

        return back()->with('success', '文件已移入回收站，可随时在回收站中恢复');
    }

    /**
     * 发送当前系统文件到企微用户
     * 用于从文件详情页推送到企微
     */
    public function sendToWecom(Request $request)
    {
        $request->validate([
            'file_path'  => 'required|string',
            'file_name'  => 'required|string',
            'file_type'  => 'required|string',  // 企微素材类型: image/file
            'wecom_user' => 'required|string',  // 企业微信 userid
        ]);

        $filePath = $request->input('file_path');
        $fileName = $request->input('file_name');
        $fileType = $request->input('file_type');
        $toUser   = $request->input('wecom_user');

        // 上传到企微获取 media_id
        $result = $this->wecom->uploadMedia($fileType, $filePath, $fileName);

        if (($result['errcode'] ?? -1) !== 0) {
            return back()->with('error', '文件上传企微失败: ' . ($result['errmsg'] ?? '未知错误'));
        }

        $mediaId = $result['media_id'];
        $sendResult = $this->wecom->sendFileMessage($toUser, $mediaId);

        if (($sendResult['errcode'] ?? -1) !== 0) {
            return back()->with('error', '文件发送失败: ' . ($sendResult['errmsg'] ?? '未知错误'));
        }

        return back()->with('success', "文件「{$fileName}」已成功发送到企业微信");
    }

    /**
     * 获取已绑定企微的用户列表（供前端选择接收人）
     */
    public function boundUsers()
    {
        $users = \App\Models\User::whereNotNull('wecom_userid')
            ->select('id', 'name', 'username', 'wecom_userid', 'department_id')
            ->with('department:id,name')
            ->get();

        return response()->json($users);
    }
}
