<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Department;
use App\Models\Role;
use App\Models\Status;
use App\Models\TaskType;
use App\Models\Notification;
use App\Models\Todo;
use App\Models\Project;
use App\Models\TemplateTask;
use Illuminate\Http\Request;
use App\Models\File;
use App\Models\OperationLog;
use App\Helpers\OperationLogHelper;
use App\Notifications\SystemNotification;
use App\Services\TrashService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            // 管理员始终放行；项目总经理仅允许访问发送通知功能
            if ($user->isAdmin()) {
                return $next($request);
            }
            if ($user->isProjectManager()) {
                $routeName = $request->route()->getName();
                $allowedRoutes = ['admin.send-notification', 'admin.notification.post'];
                if (in_array($routeName, $allowedRoutes)) {
                    return $next($request);
                }
            }
            abort(403);
            return $next($request);
        });
    }

    public function index()
    {
        $stats = [
            'projects' => \App\Models\Project::count(),
            'users' => User::count(),
            'files' => File::count(),
            'tasks' => \App\Models\Task::count(),
        ];
        return view('admin.index', compact('stats'));
    }

    public function users()
    {
        $users = User::with('department', 'role')->paginate(10);
        return view('admin.users', compact('users'));
    }

    public function deletedUsers()
    {
        $users = User::onlyTrashed()->with('department', 'role')->paginate(10);
        return view('admin.deleted-users', compact('users'));
    }

    public function restoreUser($userId)
    {
        $user = User::withTrashed()->findOrFail($userId);
        $user->restore();

        OperationLogHelper::log('用户管理', '恢复用户', "管理员恢复了用户「{$user->name}」");

        return redirect()->route('admin.users.deleted')->with('success', '用户已恢复');
    }

    public function forceDeleteUser($userId)
    {
        $user = User::withTrashed()->findOrFail($userId);
        $userName = $user->name;

        // 硬删用户会触发大量外键 CASCADE（files / messages / tasks / projects …），
        // 这些级联由数据库执行、不经过 Eloquent，因此必须先把其名下的物理文件清干净，
        // 否则头像、任务文件、聊天附件都会变成无人引用的孤儿文件。
        TrashService::purgeUserAssets($user);

        $user->forceDelete();

        OperationLogHelper::log('用户管理', '彻底删除用户', "管理员彻底删除了用户「{$userName}」及其关联的文件、任务与项目");

        return redirect()->route('admin.users.deleted')->with('success', '用户及其关联数据已彻底删除');
    }

    public function createUser()
    {
        $departments = Department::all();
        $roles = Role::all();
        return view('admin.create-user', compact('departments', 'roles'));
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'username' => ['required', \Illuminate\Validation\Rule::unique('users')->whereNull('deleted_at')],
            'password' => 'required',
            'name' => 'required',
            'department_id' => 'required',
            'role_id' => 'required',
        ]);

        User::create([
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'name' => $request->name,
            'department_id' => $request->department_id,
            'role_id' => $request->role_id,
            'is_active' => true,
        ]);

        OperationLogHelper::log('用户管理', '创建用户', "管理员创建用户「{$request->name}」");

        return redirect()->route('admin.users')->with('success', '用户创建成功');
    }

    public function editUser(User $user)
    {
        $departments = Department::all();
        $roles = Role::all();
        return view('admin.edit-user', compact('user', 'departments', 'roles'));
    }

    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'username' => ['required', \Illuminate\Validation\Rule::unique('users')->whereNull('deleted_at')->ignore($user->id)],
            'name' => 'required',
            'department_id' => 'required',
            'role_id' => 'required',
            'is_active' => 'required|boolean',
        ]);

        $data = $request->only(['username', 'name', 'department_id', 'role_id', 'is_active']);
        
        if ($request->password) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        OperationLogHelper::log('用户管理', '更新用户', "管理员更新用户「{$user->name}」信息");

        return redirect()->route('admin.users')->with('success', '用户更新成功');
    }

    public function deleteUser(User $user)
    {
        $userName = $user->name;
        $user->delete();

        OperationLogHelper::log('用户管理', '删除用户', "管理员删除用户「{$userName}」");

        return redirect()->route('admin.users')->with('success', '用户删除成功');
    }

    public function departments()
    {
        $departments = Department::paginate(10);
        return view('admin.departments', compact('departments'));
    }

    public function createDepartment()
    {
        return view('admin.create-department');
    }

    public function storeDepartment(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'code' => 'required|unique:departments',
            'type' => 'required',
            'sort_order' => 'required|integer',
        ]);

        Department::create($request->all());

        OperationLogHelper::log('部门管理', '创建部门', "管理员创建部门「{$request->name}」");

        return redirect()->route('admin.departments')->with('success', '部门创建成功');
    }

    public function editDepartment(Department $department)
    {
        return view('admin.edit-department', compact('department'));
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $request->validate([
            'name' => 'required',
            'code' => 'required|unique:departments,code,' . $department->id,
            'type' => 'required',
            'sort_order' => 'required|integer',
        ]);

        $department->update($request->all());

        OperationLogHelper::log('部门管理', '更新部门', "管理员更新部门「{$department->name}」");

        return redirect()->route('admin.departments')->with('success', '部门更新成功');
    }

    public function deleteDepartment(Department $department)
    {
        $deptName = $department->name;

        DB::transaction(function () use ($department) {
            $userIds = $department->users()->pluck('id')->toArray();

            // 获取该部门所有任务 ID
            $taskIds = $department->tasks()->pluck('id')->toArray();

            if (!empty($taskIds)) {
                // 删除任务关联的文件（files.task_id cascade）
                \App\Models\File::whereIn('task_id', $taskIds)->delete();
                // 删除任务的延期申请（extension_applications.task_id cascade）
                \App\Models\ExtensionApplication::whereIn('task_id', $taskIds)->delete();
            }

            if (!empty($userIds)) {
                // 删除通知
                \App\Models\Notification::whereIn('user_id', $userIds)->delete();
                \App\Models\Notification::whereIn('sender_id', $userIds)->delete();

                // 删除待办
                \App\Models\Todo::whereIn('user_id', $userIds)->delete();

                // 删除消息
                \App\Models\Message::whereIn('user_id', $userIds)->delete();
                \App\Models\Message::whereIn('receiver_id', $userIds)->delete();

                // 删除项目日志
                \App\Models\ProjectLog::whereIn('user_id', $userIds)->delete();

                // 删除用户创建的 project（creator_id 关联）
                \App\Models\Project::whereIn('creator_id', $userIds)->delete();

                // 删除用户上传/审核的文件
                \App\Models\File::whereIn('uploader_id', $userIds)->delete();
                \App\Models\File::whereIn('auditor_id', $userIds)->delete();

                // 删除文件版本
                \App\Models\FileVersion::whereIn('uploader_id', $userIds)->delete();
                \App\Models\FileVersion::whereIn('auditor_id', $userIds)->delete();

                // 删除延期申请
                \App\Models\ExtensionApplication::whereIn('applicant_id', $userIds)->delete();
                \App\Models\ExtensionApplication::whereIn('approver_id', $userIds)->delete();

                // 删除默认成员
                \App\Models\DefaultMember::whereIn('executor_id', $userIds)->delete();
                \App\Models\DefaultMember::whereIn('auditor_id', $userIds)->delete();

                // 删除模板任务
                \App\Models\TemplateTask::whereIn('executor_id', $userIds)->delete();
                \App\Models\TemplateTask::whereIn('auditor_id', $userIds)->delete();
            }

            // 删除该部门的所有任务
            $department->tasks()->delete();

            // 删除该部门的所有任务类型
            \App\Models\TaskType::where('department_id', $department->id)->delete();

            // 删除该部门的所有用户成员
            $department->users()->delete();

            // 删除该部门的部门文件
            $department->departmentFiles()->delete();

            // 最后删除部门
            $department->delete();
        });

        OperationLogHelper::log('部门管理', '删除部门', "管理员删除部门「{$deptName}」及其所有用户和任务");

        return redirect()->route('admin.departments')->with('success', '部门及其所有用户和任务已删除');
    }

    public function roles()
    {
        $roles = Role::paginate(10);
        return view('admin.roles', compact('roles'));
    }

    public function createRole()
    {
        return view('admin.create-role');
    }

    public function storeRole(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'code' => 'required|unique:roles',
        ]);

        Role::create($request->all());

        OperationLogHelper::log('角色管理', '创建角色', "管理员创建角色「{$request->name}」");

        return redirect()->route('admin.roles')->with('success', '角色创建成功');
    }

    public function editRole(Role $role)
    {
        return view('admin.edit-role', compact('role'));
    }

    public function updateRole(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required',
            'code' => 'required|unique:roles,code,' . $role->id,
        ]);

        $role->update($request->all());

        OperationLogHelper::log('角色管理', '更新角色', "管理员更新角色「{$role->name}」");

        return redirect()->route('admin.roles')->with('success', '角色更新成功');
    }

    public function deleteRole(Role $role)
    {
        $roleName = $role->name;
        $role->delete();

        OperationLogHelper::log('角色管理', '删除角色', "管理员删除角色「{$roleName}」");

        return redirect()->route('admin.roles')->with('success', '角色删除成功');
    }

    public function statuses()
    {
        // 从状态机类中提取所有状态，合并自定义 label 覆盖
        $allStates = Status::allStates();
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage();
        $perPage = 15;
        $statuses = new \Illuminate\Pagination\LengthAwarePaginator(
            $allStates->forPage($currentPage, $perPage)->values(),
            $allStates->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
        return view('admin.statuses', compact('statuses'));
    }

    public function editStatus(Status $status)
    {
        $stateClass = $status->state_class;
        $defaultLabel = Status::getStateDefaultLabel($stateClass);
        $defaultColor = Status::getStateDefaultColor($stateClass);

        return view('admin.edit-status', compact('status', 'defaultLabel', 'defaultColor'));
    }

    public function updateStatus(Request $request, Status $status)
    {
        $request->validate([
            'custom_label' => 'nullable|string|max:255',
        ]);

        $status->custom_label = $request->custom_label ?: null;
        $status->save();

        $displayName = Status::getLabel($status->state_class);

        OperationLogHelper::log('状态管理', '更新状态标签', "管理员更新状态「{$displayName}」的标签");

        return redirect()->route('admin.statuses')->with('success', '状态标签更新成功');
    }

    // 任务类型管理
    public function taskTypes()
    {
        $taskTypes = TaskType::with('department')->orderBy('sort_order')->paginate(10);
        $departments = Department::projectDepartments()->get();
        return view('admin.task-types', compact('taskTypes', 'departments'));
    }

    public function createTaskType()
    {
        $departments = Department::projectDepartments()->get();
        return view('admin.create-task-type', compact('departments'));
    }

    public function storeTaskType(Request $request)
    {
        $request->validate([
            'name' => 'required',
        ]);

        TaskType::create($request->all());

        OperationLogHelper::log('任务类型管理', '创建任务类型', "管理员创建任务类型「{$request->name}」");

        return redirect()->route('admin.task-types')->with('success', '任务类型创建成功');
    }

    public function editTaskType(TaskType $taskType)
    {
        $departments = Department::projectDepartments()->get();
        return view('admin.edit-task-type', compact('taskType', 'departments'));
    }

    public function updateTaskType(Request $request, TaskType $taskType)
    {
        $request->validate([
            'name' => 'required',
        ]);

        $taskType->update($request->all());

        OperationLogHelper::log('任务类型管理', '更新任务类型', "管理员更新任务类型「{$taskType->name}」");

        return redirect()->route('admin.task-types')->with('success', '任务类型更新成功');
    }

    public function deleteTaskType(TaskType $taskType)
    {
        $typeName = $taskType->name;
        $taskType->delete();

        OperationLogHelper::log('任务类型管理', '删除任务类型', "管理员删除任务类型「{$typeName}」");

        return redirect()->route('admin.task-types')->with('success', '任务类型删除成功');
    }

    public function fileRepository(Request $request)
    {
        $query = File::with('task.project', 'uploader');

        // 按文件名查询
        if ($request->filled('file_name')) {
            $query->where('original_name', 'like', '%' . $request->file_name . '%');
        }

        // 按项目名称查询
        if ($request->filled('project_name')) {
            $query->whereHas('task.project', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->project_name . '%');
            });
        }

        // 按上传日期范围查询
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // 按文件状态查询
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $files = $query->orderBy('created_at', 'desc')->paginate(10)->appends($request->all());

        return view('admin.file-repository', compact('files'));
    }

    public function operationLogs()
    {
        $logs = OperationLog::with('user')->orderBy('created_at', 'desc')->paginate(10);
        return view('admin.operation-logs', compact('logs'));
    }

    public function sendNotification()
    {
        return view('admin.send-notification');
    }

    public function postNotification(Request $request)
    {
        $request->validate([
            'title' => 'required',
            'content' => 'required',
        ]);

        $users = User::all();
        foreach ($users as $user) {
            $user->notify(new SystemNotification(
                title: $request->title,
                content: $request->content,
                senderId: auth()->id(),
                type: 'system',
            ));
        }

        OperationLogHelper::log('通知管理', '发送通知', "管理员发送系统通知「{$request->title}」");

        return redirect()->route('admin.send-notification')->with('success', '通知发送成功');
    }

    public function todos()
    {
        $todos = \App\Models\Todo::with('user')->paginate(10);
        return view('admin.todos', compact('todos'));
    }

    /**
     * 后台服务状态页面
     */
    public function services()
    {
        $schedulerRunning = $this->isSchedulerRunning();
        $queueRunning = $this->isProcessRunning('queue:work');
        $viteRunning = $this->isProcessRunning('vite');

        // 磁盘空间
        $diskFree = disk_free_space(storage_path());
        $diskTotal = disk_total_space(storage_path());
        $diskUsedPercent = $diskTotal > 0 ? round((1 - $diskFree / $diskTotal) * 100, 1) : 0;

        // 数据库大小与名称（兼容 SQLite / MySQL）
        $connection = config('database.default');
        if ($connection === 'sqlite') {
            $dbPath = database_path('database.sqlite');
            $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
            $dbName = basename($dbPath);
        } else {
            $dbName = config("database.connections.{$connection}.database", 'pm_db');
            try {
                $dbSize = (int) \Illuminate\Support\Facades\DB::table('information_schema.tables')
                    ->where('table_schema', $dbName)
                    ->selectRaw('COALESCE(SUM(data_length + index_length), 0) AS total_size')
                    ->value('total_size');
            } catch (\Exception $e) {
                \Log::warning('获取数据库大小失败', ['error' => $e->getMessage()]);
                $dbSize = 0;
            }
        }

        // 备份文件列表
        $backupDir = storage_path('app/backups');
        $backups = [];
        if (is_dir($backupDir)) {
            $files = scandir($backupDir);
            foreach ($files as $f) {
                if (str_ends_with($f, '.sqlite') || str_ends_with($f, '.zip') || str_ends_with($f, '.sql')) {
                    $filePath = $backupDir . '/' . $f;
                    $backups[] = [
                        'name' => $f,
                        'size' => filesize($filePath),
                        'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                    ];
                }
            }
            usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));
        }

        $serverInfo = [
            'os' => PHP_OS,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_time' => now()->toDateTimeString(),
            'disk_free' => $diskFree,
            'disk_total' => $diskTotal,
            'disk_used_percent' => $diskUsedPercent,
            'db_size' => $dbSize,
            'db_name' => $dbName,
        ];

        return view('admin.services', compact(
            'schedulerRunning', 'queueRunning', 'viteRunning',
            'serverInfo', 'backups'
        ));
    }

    /**
     * 备份数据库（MySQL 使用 mysqldump，SQLite 直接复制数据库文件）
     */
    public function backupDatabase()
    {
        try {
            $backupDir = storage_path('app/backups');
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $connection = config('database.default');

            if ($connection === 'mysql') {
                $host = config('database.connections.mysql.host', '127.0.0.1');
                $port = config('database.connections.mysql.port', '3306');
                $user = config('database.connections.mysql.username', 'root');
                $pass = config('database.connections.mysql.password', '');
                $db = config('database.connections.mysql.database', 'pm_db');

                $backupName = 'backup_' . date('Ymd_His') . '.sql';
                $backupPath = $backupDir . '/' . $backupName;

                $cmd = sprintf(
                    'mysqldump -h%s -P%s -u%s -p%s --single-transaction --routines --triggers %s',
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($user),
                    escapeshellarg($pass),
                    escapeshellarg($db)
                );
                $output = [];
                $exitCode = 0;
                exec($cmd . ' > ' . escapeshellarg($backupPath) . ' 2>&1', $output, $exitCode);

                if ($exitCode !== 0 || !file_exists($backupPath) || filesize($backupPath) === 0) {
                    @unlink($backupPath);
                    throw new \Exception('mysqldump 执行失败，请确认服务器已安装 mysql 客户端（mysqldump）且数据库连接信息正确');
                }
            } else {
                // SQLite：直接复制数据库文件
                $dbPath = database_path('database.sqlite');
                if (!file_exists($dbPath)) {
                    return back()->with('error', '数据库文件不存在');
                }
                $backupName = 'backup_' . date('Ymd_His') . '.sqlite';
                $backupPath = $backupDir . '/' . $backupName;
                copy($dbPath, $backupPath);
            }

            // 清理过期备份（.sqlite / .sql / .zip）
            $retentionDays = config('pm.backup_retention_days', 30);
            $cutoff = now()->subDays($retentionDays)->timestamp;
            $oldBackups = array_merge(
                glob($backupDir . '/*.sqlite') ?: [],
                glob($backupDir . '/*.sql') ?: [],
                glob($backupDir . '/*.zip') ?: []
            );
            foreach ($oldBackups as $oldBackup) {
                if (filemtime($oldBackup) < $cutoff) {
                    unlink($oldBackup);
                }
            }

            OperationLogHelper::log('系统管理', '备份数据库', "创建数据库备份：{$backupName}");

            return back()->with('success', "数据库备份成功：{$backupName}");
        } catch (\Exception $e) {
            \Log::error('数据库备份失败', ['error' => $e->getMessage()]);
            return back()->with('error', '数据库备份失败：' . $e->getMessage());
        }
    }

    /**
     * 检查指定进程是否在运行
     */
    private function isProcessRunning(string $processName): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: 使用 tasklist
            $output = [];
            $exitCode = 0;
            exec('tasklist /FI "IMAGENAME eq php.exe" 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                return false;
            }

            // 检查 php.exe 进程的命令行参数中是否包含目标进程名
            // 通过 wmic 获取更详细的进程信息
            $cmdOutput = [];
            exec('wmic process where "name=\'php.exe\'" get commandline 2>&1', $cmdOutput);
            foreach ($cmdOutput as $line) {
                if (stripos($line, $processName) !== false) {
                    return true;
                }
            }
            return false;
        } else {
            // Linux/Mac: 优先使用 systemctl 检测服务状态
            if ($processName === 'queue:work') {
                exec('systemctl is-active pm-queue 2>/dev/null', $svcOut, $svcRc);
                if ($svcRc === 0 && trim(implode('', $svcOut)) === 'active') {
                    return true;
                }
            }
            // 备用方案：pgrep（可能被 SELinux 限制）
            $output = [];
            exec("pgrep -f '{$processName}' 2>/dev/null", $output);
            return !empty($output);
        }
    }

    /**
     * 检查定时调度（Scheduler）是否运行
     *
     * 兼容两种部署模式（Laravel 不允许两者同时使用，否则任务会重复执行）：
     *  - 开发环境（start.sh / start.bat）：schedule:work 常驻进程；
     *  - 生产环境（deploy.sh）：crond 服务运行。
     * 任一模式生效即视为定时任务已启动。
     */
    private function isSchedulerRunning(): bool
    {
        // 模式一：开发环境 schedule:work 常驻进程
        if ($this->isProcessRunning('schedule:work')) {
            return true;
        }
        // Windows 无 crond，仅支持 schedule:work 模式
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return false;
       }
       // 模式二：生产环境 crond 服务运行
       exec('systemctl is-active crond 2>/dev/null', $crondOut, $crondRc);
       if ($crondRc !== 0 || trim(implode('', $crondOut)) !== 'active') {
           return false;
       }
       return true;
    }

    /**
     * 显示批量导入用户的表单
     */
    public function importUsersForm()
    {
        $departments = Department::all();
        $roles = Role::all();
        return view('admin.import-users', compact('departments', 'roles'));
    }

    /**
     * 从 CSV 文件批量导入用户
     */
    public function importUsers(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel|max:2048',
        ]);

        $file = $request->file('csv_file');
        $path = $file->getRealPath();

        $success = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return back()->with('error', '无法读取 CSV 文件')->withInput();
        }

        // 检测 BOM 并跳过
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $lineNumber = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            // 跳过空行
            if (empty(array_filter($row, fn($v) => !is_null($v) && $v !== ''))) {
                continue;
            }

            // 检查列数
            if (count($row) < 5) {
                $failed++;
                $errors[] = "第 {$lineNumber} 行：列数不足（需要 5 列，实际 " . count($row) . " 列），已跳过";
                continue;
            }

            $username    = trim($row[0]);
            $password    = trim($row[1]);
            $name        = trim($row[2]);
            $departmentId = (int) trim($row[3]);
            $roleId      = (int) trim($row[4]);

            // 必填校验
            if (empty($username) || empty($password) || empty($name)) {
                $failed++;
                $errors[] = "第 {$lineNumber} 行：用户名/密码/姓名不能为空，已跳过";
                continue;
            }

            // 部门存在校验
            $department = Department::find($departmentId);
            if (!$department) {
                $failed++;
                $errors[] = "第 {$lineNumber} 行：部门 ID {$departmentId} 不存在（{$name}），已跳过";
                continue;
            }

            // 角色存在校验
            $role = Role::find($roleId);
            if (!$role) {
                $failed++;
                $errors[] = "第 {$lineNumber} 行：角色 ID {$roleId} 不存在（{$name}），已跳过";
                continue;
            }

            // 密码复杂度校验
            if (strlen($password) < 6 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $failed++;
                $errors[] = "第 {$lineNumber} 行：密码不符合要求（{$name}），需 ≥6 位且含字母+数字，已跳过";
                continue;
            }

            // 检查用户名是否已存在
            if (User::where('username', $username)->whereNull('deleted_at')->exists()) {
                $skipped++;
                $errors[] = "第 {$lineNumber} 行：用户名 {$username} 已存在，已跳过";
                continue;
            }

            try {
                DB::beginTransaction();
                User::create([
                    'username'      => $username,
                    'password'      => Hash::make($password),
                    'name'          => $name,
                    'department_id' => $departmentId,
                    'role_id'       => $roleId,
                    'is_active'     => true,
                ]);
                DB::commit();
                $success++;
            } catch (\Exception $e) {
                DB::rollBack();
                $failed++;
                $errors[] = "第 {$lineNumber} 行：创建用户 {$name} 失败（" . $e->getMessage() . "），已跳过";
                \Log::error('CSV 导入用户失败', [
                    'line' => $lineNumber,
                    'username' => $username,
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        fclose($handle);

        if ($success > 0) {
            OperationLogHelper::log('用户管理', '批量导入用户', "从 CSV 批量导入：成功 {$success}，跳过 {$skipped}，失败 {$failed}");
        }

        session()->flash('import_result', compact('success', 'skipped', 'failed', 'errors'));

        return redirect()->route('admin.users.import-form')->with(
            'success',
            $success > 0 ? "导入完成：成功 {$success} 人" . ($failed > 0 ? "，失败 {$failed} 人" : '') : null
        );
    }

    // ========== 聊天记录管理 ==========

    /**
     * 聊天记录列表
     */
    public function chatMessages(Request $request)
    {
        $query = \App\Models\Message::with(['user', 'project']);

        // 按项目筛选
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // 按聊天类型筛选
        if ($request->filled('chat_type')) {
            if ($request->chat_type === 'group') {
                $query->where('is_private', false);
            } elseif ($request->chat_type === 'private') {
                $query->where('is_private', true);
            }
        }

        // 按时间范围筛选
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $messages = $query->orderBy('created_at', 'desc')->paginate(20)->appends($request->query());

        // 统计信息
        $stats = [
            'total' => \App\Models\Message::count(),
            'group' => \App\Models\Message::where('is_private', false)->count(),
            'private' => \App\Models\Message::where('is_private', true)->count(),
            'with_file' => \App\Models\Message::whereNotNull('file_path')->count(),
            'total_file_size' => $this->getChatFileSize(),
        ];

        // 项目列表（用于筛选下拉框）
        $projects = \App\Models\Project::orderBy('name')->get(['id', 'name']);

        return view('admin.chat-messages', compact('messages', 'stats', 'projects'));
    }

    /**
     * 批量删除聊天记录
     */
    public function deleteChatMessages(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:messages,id',
        ]);

        $ids = $request->ids;

        // 软删除：保留附件物理文件，管理员可在回收站恢复或彻底删除。
        $deletedCount = \App\Models\Message::whereIn('id', $ids)->delete();

        OperationLogHelper::log('聊天记录管理', '批量删除聊天记录', "管理员将 {$deletedCount} 条聊天记录移入回收站");

        return redirect()->route('admin.chat-messages')->with('success', "已将 {$deletedCount} 条聊天记录移入回收站，可由管理员恢复");
    }

    /**
     * 按项目清空聊天记录
     */
    public function clearProjectChat(Request $request)
    {
        $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'chat_type' => 'required|in:all,group,private',
        ]);

        $projectId = $request->project_id;
        $project = \App\Models\Project::find($projectId);

        // 软删除：保留附件物理文件，管理员可在回收站恢复或彻底删除。
        $deletedCount = \App\Models\Message::where('project_id', $projectId);
        if ($request->chat_type === 'group') {
            $deletedCount->where('is_private', false);
        } elseif ($request->chat_type === 'private') {
            $deletedCount->where('is_private', true);
        }
        $deletedCount = $deletedCount->delete();

        OperationLogHelper::log('聊天记录管理', '清空项目聊天记录', "管理员将项目「{$project->name}」的 {$deletedCount} 条聊天记录移入回收站");

        return redirect()->route('admin.chat-messages')->with('success', "已将项目「{$project->name}」的 {$deletedCount} 条聊天记录移入回收站，可由管理员恢复");
    }

    /**
     * 计算聊天文件总大小
     */
    private function getChatFileSize(): string
    {
        $totalBytes = 0;
        $files = \App\Models\Message::whereNotNull('file_path')->pluck('file_path');

        foreach ($files as $path) {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                $totalBytes += \Illuminate\Support\Facades\Storage::disk('public')->size($path);
            }
        }

        if ($totalBytes < 1024) {
            return $totalBytes . ' B';
        } elseif ($totalBytes < 1024 * 1024) {
            return round($totalBytes / 1024, 2) . ' KB';
        } elseif ($totalBytes < 1024 * 1024 * 1024) {
            return round($totalBytes / (1024 * 1024), 2) . ' MB';
        }
        return round($totalBytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}