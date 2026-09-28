<?php

namespace App\Http\Controllers;

use App\Models\DepartmentFile;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = auth()->user();
        $files = DepartmentFile::where('department_id', $user->department_id)
            ->where(function ($query) use ($user) {
                // 支援中心部门文件列表只显示当前用户有权查看的资料文件。
                $query->where('visibility', 'all')
                    ->orWhere('visibility', 'department')
                    ->orWhere(function ($privateQuery) use ($user) {
                        $privateQuery->where('visibility', 'private')
                            ->where('uploader_id', $user->id);
                    });
            })
            ->with('uploader')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $stats = [
            'total_files' => DepartmentFile::where('department_id', $user->department_id)
                ->where(function ($query) use ($user) {
                    $query->where('visibility', 'all')
                        ->orWhere('visibility', 'department')
                        ->orWhere(function ($privateQuery) use ($user) {
                            $privateQuery->where('visibility', 'private')
                                ->where('uploader_id', $user->id);
                        });
                })
                ->count(),
            'my_files' => DepartmentFile::where('department_id', $user->department_id)
                ->where('uploader_id', $user->id)->count(),
        ];

        return view('support.index', compact('files', 'stats'));
    }

    public function departmentFiles()
    {
        $user = auth()->user();
        $files = DepartmentFile::where('department_id', $user->department_id)
            ->where(function ($query) use ($user) {
                // 资料文件权限范围在所有支援部门文件入口保持一致。
                $query->where('visibility', 'all')
                    ->orWhere('visibility', 'department')
                    ->orWhere(function ($privateQuery) use ($user) {
                        $privateQuery->where('visibility', 'private')
                            ->where('uploader_id', $user->id);
                    });
            })
            ->with('uploader')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('support.department-files', compact('files'));
    }

    public function sendNotification()
    {
        return view('support.send-notification');
    }

    public function postNotification(Request $request)
    {
        $request->validate([
            'title' => 'required',
            'content' => 'required',
        ]);

        $user = auth()->user();
        $departmentUsers = User::where('department_id', $user->department_id)->get();

        foreach ($departmentUsers as $u) {
            $u->notify(new SystemNotification(
                title: $request->title,
                content: $request->content,
                senderId: auth()->id(),
                type: 'department',
            ));
        }

        return redirect()->route('support.send-notification')->with('success', '通知发送成功');
    }

    /**
     * 显示上传资料文件页面
     */
    public function uploadResourceFile()
    {
        $categories = DepartmentFile::categoryOptions();
        $visibilityOptions = DepartmentFile::visibilityOptions();

        return view('support.upload-resource-file', compact('categories', 'visibilityOptions'));
    }

    /**
     * 存储上传的资料文件
     */
    public function storeResourceFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'category' => 'required|in:' . implode(',', array_keys(DepartmentFile::categoryOptions())),
            'visibility' => 'required|in:' . implode(',', array_keys(DepartmentFile::visibilityOptions())),
        ]);

        $user = auth()->user();
        $uploadedFile = $request->file('file');
        $originalName = $uploadedFile->getClientOriginalName();
        
        // 搜索同名文件，确定版本号
        $existingFiles = DepartmentFile::where('original_name', $originalName)
            ->where('department_id', $user->department_id)
            ->orderBy('version', 'desc')
            ->first();
        
        $version = $existingFiles ? $existingFiles->version + 1 : 1;
        
        // 生成文件名
        $extension = $uploadedFile->getClientOriginalExtension();
        $fileName = uniqid() . "_v{$version}.{$extension}";
        
        // 存储文件
        $path = $uploadedFile->storeAs(
            "department-" . $user->department_id,
            $fileName,
            'file_repository'
        );

        // 创建记录
        DepartmentFile::create([
            'department_id' => $user->department_id,
            'uploader_id' => $user->id,
            'name' => $fileName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $uploadedFile->getSize(),
            'mime_type' => $uploadedFile->getMimeType(),
            'category' => $request->category,
            'visibility' => $request->visibility,
            'version' => $version,
        ]);

        return redirect()->route('support.index')->with('success', '资料文件上传成功');
    }
}
