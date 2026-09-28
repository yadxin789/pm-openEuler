<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\File;
use App\Models\Task;

class FixFileAuditorId extends Command
{
    protected $signature = 'files:fix-auditor-id';
    protected $description = '修复文件的审核人ID字段';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('开始修复文件的审核人ID...');
        
        // 查找所有auditor_id为null的文件
        $files = File::whereNull('auditor_id')->get();
        
        $fixedCount = 0;
        $skippedCount = 0;
        
        foreach ($files as $file) {
            // 通过任务获取审核人ID
            $task = Task::find($file->task_id);
            
            if ($task && $task->auditor_id) {
                $file->auditor_id = $task->auditor_id;
                $file->save();
                $fixedCount++;
                $this->line("✓ 文件 [{$file->original_name}] 审核人ID已设置为: {$task->auditor_id}");
            } else {
                $skippedCount++;
                $this->warn("✗ 文件 [{$file->original_name}] 无法找到对应的任务或审核人");
            }
        }
        
        $this->info("修复完成！共修复 {$fixedCount} 个文件，跳过 {$skippedCount} 个文件。");
        
        return Command::SUCCESS;
    }
}
