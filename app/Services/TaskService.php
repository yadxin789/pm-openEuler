<?php

namespace App\Services;

use App\Models\Task;
use App\Models\File;
use App\Models\Todo;
use App\Models\FileVersion;
use App\Notifications\AuditResultNotification;
use App\Notifications\AuditRequestNotification;
use App\Notifications\SystemNotification;
use App\States\Task\ForAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TaskService
{
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp',
        'txt', 'csv', 'zip', 'rar', '7z',
    ];

    /**
     * 上传任务文件
     */
    public function storeFile(Task $task, $uploadedFile, int $userId): array
    {
        return DB::transaction(function () use ($task, $uploadedFile, $userId) {
            $extension = strtolower($uploadedFile->getClientOriginalExtension());

            if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                throw new \RuntimeException('不支持的文件类型：.' . $extension);
            }

            $fileName = uniqid() . '_v1.' . $extension;
            $path = $uploadedFile->storeAs(
                "project-{$task->project_id}/task-{$task->id}",
                $fileName,
                'file_repository'
            );

            if (!$path) {
                throw new \RuntimeException('文件保存失败，请重试');
            }

            $taskFile = File::create([
                'task_id' => $task->id,
                'uploader_id' => $userId,
                'auditor_id' => $task->auditor_id,
                'name' => $fileName,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'path' => $path,
                'size' => $uploadedFile->getSize(),
                'mime_type' => $uploadedFile->getMimeType(),
                'version' => 1,
                'status' => 'pending',
            ]);

            Todo::where('related_type', 'task')
                ->where('related_id', $task->id)
                ->where('user_id', $task->executor_id)
                ->where('type', 'task')
                ->delete();

            $task->status->transitionTo(\App\States\Task\ForAudit::class);

            return [
                'file' => $taskFile,
                'success' => true,
            ];
        });
    }

    /**
     * 审核通过
     */
    public function approve(Task $task, ?string $reason, int $userId): void
    {
        DB::transaction(function () use ($task, $reason, $userId) {
            $pendingFile = $task->files()->where('status', 'pending')->first();

            if (!$pendingFile) {
                // 没有待审核文件时，使用原来的完成逻辑
                $this->transitionToCompletedOrOverdueCompleted($task);
                return;
            }

            $isUpdate = $pendingFile->version > 1
                || $task->files()->where('status', 'approved')->where('id', '!=', $pendingFile->id)->exists();

            if ($isUpdate) {
                $oldFile = $task->files()->where('status', 'approved')->first();
                if ($oldFile && $oldFile->id != $pendingFile->id) {
                    // 软删除旧版本文件：保留物理文件，管理员可在回收站恢复或彻底删除。
                    $oldFile->delete();
                }
            }

            $pendingFile->update([
                'status' => 'approved',
                'auditor_id' => $userId,
                'audit_time' => now(),
                'audit_reason' => $reason,
            ]);

            $this->transitionToCompletedOrOverdueCompleted($task);

            if ($isUpdate) {
                $this->notifyProjectMembers($task, $pendingFile, $userId);
            }
        });
    }

    /**
     * 将任务转为已完成或逾期XX天完成
     */
    private function transitionToCompletedOrOverdueCompleted(Task $task): void
    {
        $task->completed_at = now();
        $task->save();

        // 判断任务是否原本已逾期
        if ($task->status->equals(\App\States\Task\Overdue::class)) {
            // 原来是逾期状态，转为逾期XX天完成
            $task->status->transitionTo(\App\States\Task\OverdueCompleted::class);
        } else {
            $task->status->transitionTo(\App\States\Task\Completed::class);
        }
    }

    /**
     * 审核驳回
     */
    public function reject(Task $task, ?string $reason, int $userId): void
    {
        DB::transaction(function () use ($task, $reason, $userId) {
            $pendingFile = $task->files()->where('status', 'pending')->first();

            if ($pendingFile) {
                $isUpdate = $pendingFile->version > 1
                    || $task->files()->where('status', 'approved')->where('id', '!=', $pendingFile->id)->exists();

                if ($isUpdate) {
                    // 更新文件被驳回：软删除待审核文件（保留物理文件供回收站恢复），任务回到已完成状态
                    $pendingFile->delete();

                    // 清理审核人的待办事项
                    Todo::where('related_type', 'task')
                        ->where('related_id', $task->id)
                        ->where('user_id', $task->auditor_id)
                        ->where('type', 'audit')
                        ->delete();

                    // 回到已完成状态
                    $task->status->transitionTo(\App\States\Task\Completed::class);

                    // 通知执行人：更新文件已驳回
                    $uploader = \App\Models\User::find($pendingFile->uploader_id);
                    if ($uploader) {
                        $uploader->notify(new AuditResultNotification(
                            taskName: $task->name,
                            projectName: $task->project->name ?? '',
                            taskId: $task->id,
                            approved: false,
                            reason: $reason,
                            senderId: $userId,
                            version: $pendingFile->version,
                            isUpdate: true,
                        ));
                    }
                } else {
                    // 首次上传被驳回：保留记录与物理文件，仅标记为已驳回
                    $pendingFile->update([
                        'status' => 'rejected',
                        'auditor_id' => $userId,
                        'audit_time' => now(),
                        'audit_reason' => $reason,
                    ]);

                    $task->status->transitionTo(\App\States\Task\Rejected::class);
                }
            }
        });
    }

    /**
     * 更新文件
     */
    public function updateFile(Task $task, $uploadedFile, int $userId): void
    {
        DB::transaction(function () use ($task, $uploadedFile, $userId) {
            $currentFile = $task->files()->where('status', 'approved')->first();

            if (!$currentFile) {
                throw new \RuntimeException('没有可更新的文件');
            }

            $extension = strtolower($uploadedFile->getClientOriginalExtension());
            if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                throw new \RuntimeException('不支持的文件类型');
            }

            // 为旧版本生成**独立的快照文件**（真实复制一份），
            // 而不是只记录一个指向同一个文件的路径——
            // 否则旧版本被彻底删除时，历史版本的可追溯性会一并消失。
            $snapshotPath = $this->createFileSnapshot($currentFile);

            // 快照生成失败时不写版本记录，避免留下指向不存在文件的死记录
            if ($snapshotPath) {
                FileVersion::create([
                    'file_id' => $currentFile->id,
                    'task_id' => $task->id,
                    'version' => $currentFile->version,
                    'path' => $snapshotPath,
                    'uploader_id' => $currentFile->uploader_id,
                    'auditor_id' => $currentFile->auditor_id,
                    'status' => 'approved',
                ]);
            }

            $newVersion = $currentFile->version + 1;
            $fileName = uniqid() . "_v{$newVersion}." . $extension;
            $path = $uploadedFile->storeAs(
                "project-{$task->project_id}/task-{$task->id}",
                $fileName,
                'file_repository'
            );

            File::create([
                'task_id' => $task->id,
                'uploader_id' => $userId,
                'auditor_id' => $task->auditor_id,
                'name' => $fileName,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'path' => $path,
                'size' => $uploadedFile->getSize(),
                'mime_type' => $uploadedFile->getMimeType(),
                'version' => $newVersion,
                'status' => 'pending',
            ]);

            if (!$task->status->equals(ForAudit::class)) {
                $task->status->transitionTo(ForAudit::class);
            }

            Todo::create([
                'user_id' => $task->auditor_id,
                'type' => 'audit',
                'title' => "文件更新审核：【{$task->project->name}】{$task->name}",
                'related_type' => 'task',
                'related_id' => $task->id,
            ]);

            $auditor = $task->auditor;
            if ($auditor) {
                $auditor->notify(new AuditRequestNotification(
                    taskName: $task->name,
                    projectName: $task->project->name ?? '',
                    taskId: $task->id,
                    senderId: $userId,
                    isUpdate: true,
                    version: $newVersion,
                ));
            }
        });

        // 事务成功后再裁剪历史快照：超出保留上限的旧版本（快照文件 + 版本记录）
        // 一并清理，避免任务被反复更新导致磁盘无限膨胀。
        TrashService::pruneFileHistory($task);
    }

    /**
     * 为文件生成独立的历史版本快照。
     *
     * 快照存放在任务文件目录下的 history/ 子目录：
     *   project-{projectId}/task-{taskId}/history/{fileId}_v{version}.{ext}
     * 与协作文档的快照机制保持一致：真实复制一份文件，而不是只记录同一路径，
     * 否则旧版本文件被彻底删除时，历史版本会一并消失。
     *
     * @return string|null 快照的相对路径；源文件不存在时返回 null（此时不记录快照，避免产生死记录）
     */
    private function createFileSnapshot(File $file): ?string
    {
        $disk = Storage::disk('file_repository');

        if (empty($file->path) || !file_exists($disk->path($file->path))) {
            return null;
        }

        $extension = pathinfo($file->path, PATHINFO_EXTENSION);
        $snapshotPath = dirname($file->path)
            . '/history/'
            . $file->getKey() . '_v' . $file->version
            . ($extension !== '' ? '.' . $extension : '');

        try {
            $disk->makeDirectory(dirname($snapshotPath));
            $disk->copy($file->path, $snapshotPath);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $snapshotPath;
    }

    /**
     * 刷新项目进度
     */
    public function refreshProjectProgress(Task $task): void
    {
        try {
            $task->project->calculateProgress();
        } catch (\Exception $e) {
            \Log::warning('刷新项目进度失败: ' . $e->getMessage());
        }
    }

    /**
     * 通知项目所有成员
     */
    private function notifyProjectMembers(Task $task, File $file, int $senderId): void
    {
        try {
            $project = $task->project;
            // 获取项目所有成员（创建者 + 所有任务的执行者和审核者）
            $members = $project->participants();

            foreach ($members as $user) {
                // 排除发送者本人
                if ($user->id == $senderId) {
                    continue;
                }

                $user->notify(new SystemNotification(
                    title: '项目文件已更新',
                    content: "【{$project->name}】任务「{$task->name}」的文件已更新至 v{$file->version}，请及时查看",
                    relatedType: 'file',
                    relatedId: $file->id,
                    senderId: $senderId,
                    type: 'info',
                ));
            }
        } catch (\Exception $e) {
            \Log::warning('发送项目成员通知失败: ' . $e->getMessage());
        }
    }
}