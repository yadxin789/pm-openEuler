<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\DepartmentFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $files = File::with('task.project', 'user')->paginate(10);
        return view('file.index', compact('files'));
    }

    /**
     * 资料文件列表 - 项目参与人员视图
     */
    public function resourceFiles()
    {
        $user = auth()->user();
        
        $categories = DepartmentFile::categoryOptions();
        
        $categoryFiles = [];
        foreach ($categories as $key => $name) {
            $query = DepartmentFile::where('category', $key)->with(['department', 'uploader']);
            
            $this->applyDepartmentFileVisibilityScope($query, $user);
            
            $categoryFiles[$key] = [
                'name' => $name,
                'files' => $query->orderBy('created_at', 'desc')->get()
            ];
        }
        
        return view('file.resource-files', compact('categoryFiles'));
    }

    /**
     * 资料文件管理 - 非项目参与人员视图（管理员）
     */
    public function manageResourceFiles()
    {
        $categories = DepartmentFile::categoryOptions();
        
        $categoryFiles = [];
        foreach ($categories as $key => $name) {
            $categoryFiles[$key] = [
                'name' => $name,
                'files' => DepartmentFile::where('category', $key)
                    ->with(['department', 'uploader'])
                    ->orderBy('created_at', 'desc')
                    ->get()
            ];
        }
        
        return view('file.manage-resource-files', compact('categoryFiles'));
    }

    public function preview(File $file)
    {
        // 检查用户是否已登录
        if (!auth()->check()) {
            return redirect()->route('login')->with('error', '请先登录');
        }
        
        $userId = auth()->id();
        
        // 权限检查
        if (!$file->canPreview($userId)) {
            \Log::warning('文件预览权限拒绝', [
                'file_id' => $file->id,
                'user_id' => $userId,
                'uploader_id' => $file->uploader_id,
                'auditor_id' => $file->auditor_id,
            ]);
            return redirect()->back()->with('error', '您没有权限查看此文件');
        }

        $path = storage_path('app/file-repository/' . $file->path);
        
        if (!file_exists($path)) {
            return redirect()->back()->with('error', '文件不存在');
        }

        // 所有文件都进入预览页面，不支持的格式由前端显示提示
        return view('file.preview', compact('file'));
    }

    /**
     * 获取文件内容（用于预览）
     */
    public function previewFileContent(File $file)
    {
        // 检查用户是否已登录
        if (!auth()->check()) {
            abort(403, '请先登录');
        }
        
        $userId = auth()->id();
        
        // 权限检查
        if (!$file->canPreview($userId)) {
            abort(403, '您没有权限访问此文件');
        }

        $path = storage_path('app/file-repository/' . $file->path);
        
        if (!file_exists($path)) {
            abort(404, '文件不存在');
        }
        
        $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
        
        // 根据文件类型设置正确的 Content-Type
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        ];
        
        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        
        // 返回文件内容，不强制下载
        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $file->original_name . '"',
        ]);
    }

    public function download(File $file)
    {
        // 使用专门的下载权限检查（比预览更严格）
        if (!$file->canDownload(auth()->id())) {
            return redirect()->back()->with('error', '您没有权限下载此文件');
        }

        $path = storage_path('app/file-repository/' . $file->path);
        
        if (!file_exists($path)) {
            return redirect()->back()->with('error', '文件不存在');
        }

        return response()->download($path, $file->original_name);
    }

    public function uploadDepartmentFile()
    {
        $categories = DepartmentFile::categoryOptions();
        $visibilityOptions = DepartmentFile::visibilityOptions();

        return view('file.upload-department', compact('categories', 'visibilityOptions'));
    }

    public function storeDepartmentFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'category' => 'required|in:' . implode(',', array_keys(DepartmentFile::categoryOptions())),
            'visibility' => 'required|in:' . implode(',', array_keys(DepartmentFile::visibilityOptions())),
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $fileName = uniqid() . '_v1.' . $file->getClientOriginalExtension();
        
        $path = $file->storeAs("department-".auth()->user()->department_id, $fileName, 'file_repository');

        DepartmentFile::create([
            'department_id' => auth()->user()->department_id,
            'uploader_id' => auth()->id(),
            'name' => $fileName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $request->category,
            'visibility' => $request->visibility,
            'version' => 1,
        ]);

        return redirect()->route('file.resource-files')->with('success', '文件上传成功');
    }

    public function downloadDepartmentFile(DepartmentFile $file)
    {
        if (!$file->canAccess(auth()->id())) {
            return redirect()->back()->with('error', '您没有权限下载此文件');
        }

        $path = storage_path('app/file-repository/' . $file->path);
        
        if (!file_exists($path)) {
            return redirect()->back()->with('error', '文件不存在');
        }

        return response()->download($path, $file->original_name);
    }

    /**
     * 预览部门文件
     */
    public function previewDepartmentFile(DepartmentFile $file)
    {
        if (!$file->canAccess(auth()->id())) {
            return response()->json(['error' => '您没有权限查看此文件'], 403);
        }

        $path = storage_path('app/file-repository/' . $file->path);
        
        if (!file_exists($path)) {
            return response()->json(['error' => '文件不存在'], 404);
        }

        $extension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
        
        // 图片文件直接返回
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'])) {
            return response()->file($path);
        }

        // 所有文件都进入预览页面，不支持的格式由前端显示提示
        return view('file.preview', compact('file', 'path'));
    }

    /**
     * 更新部门文件
     */
    public function updateDepartmentFile(Request $request, DepartmentFile $file)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        // 删除旧文件
        Storage::disk('file_repository')->delete($file->path);

        // 上传新文件
        $newFile = $request->file('file');
        $originalName = $newFile->getClientOriginalName();
        $newVersion = $file->version + 1;
        $fileName = uniqid() . "_v{$newVersion}." . $newFile->getClientOriginalExtension();
        
        $path = $newFile->storeAs("department-".$file->department_id, $fileName, 'file_repository');

        $file->update([
            'name' => $fileName,
            'original_name' => $originalName,
            'path' => $path,
            'size' => $newFile->getSize(),
            'mime_type' => $newFile->getMimeType(),
            'version' => $newVersion,
        ]);

        return redirect()->back()->with('success', '文件更新成功');
    }

    /**
     * 删除部门文件（软删除，进入回收站）
     *
     * 仅管理员或上传人可删除；物理文件保留，等待管理员在回收站恢复或彻底删除。
     */
    public function deleteDepartmentFile(DepartmentFile $file)
    {
        $user = auth()->user();
        abort_unless(
            $user && ($user->isAdmin() || $file->uploader_id === $user->id),
            403,
            '您没有权限删除该资料文件'
        );

        $file->delete();

        return redirect()->back()->with('success', '文件已移入回收站，可由管理员恢复');
    }

    /**
     * 按资料文件权限范围过滤当前用户可见的数据。
     */
    private function applyDepartmentFileVisibilityScope($query, $user): void
    {
        $query->where(function ($scope) use ($user) {
            $scope->where('visibility', 'all')
                ->orWhere(function ($departmentScope) use ($user) {
                    $departmentScope->where('visibility', 'department')
                        ->where('department_id', $user->department_id);
                })
                ->orWhere(function ($privateScope) use ($user) {
                    $privateScope->where('visibility', 'private')
                        ->where('uploader_id', $user->id);
                });
        });
    }
}