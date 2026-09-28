@extends('layouts.app')

@section('title', '审核延期申请')

@section('content')
<h1 class="mb-4">审核延期申请</h1>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>项目名称</th>
                        <th>任务名称</th>
                        <th>申请人</th>
                        <th>申请天数</th>
                        <th>申请理由</th>
                        <th>申请时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($applications as $application)
                    <tr>
                        <td><a href="{{ route('project.show', $application->task->project) }}">{{ $application->task->project->name }}</a></td>
                        <td><a href="{{ route('task.show', $application->task) }}">{{ $application->task->name }}</a></td>
                        <td>{{ $application->applicant->name }}</td>
                        <td>{{ $application->days }}天</td>
                        <td>{{ $application->reason }}</td>
                        <td>{{ $application->created_at }}</td>
                        <td>
                            @if(!auth()->user()->isProjectManager())
                            <div class="btn-group" role="group">
                                <form action="{{ route('extension.approve', $application) }}" method="POST" style="display: inline;" class="approve-form">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm approve-btn" onclick="handleApprove(this)">批准</button>
                                </form>
                                <button type="button" class="btn btn-danger btn-sm reject-btn" data-bs-toggle="modal" data-bs-target="#rejectModal-{{ $application->id }}">驳回</button>
                            </div>
                            @else
                            <span class="text-muted">只读</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        
        @if($applications->isEmpty())
        <p class="text-center text-muted mt-4">暂无待审批的延期申请</p>
        @endif
    </div>
</div>

{{-- 驳回确认弹窗 --}}
@foreach($applications as $application)
<div class="modal fade" id="rejectModal-{{ $application->id }}" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel-{{ $application->id }}" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rejectModalLabel-{{ $application->id }}">驳回延期申请</h5>
                <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="{{ route('extension.reject', $application) }}" method="POST" class="reject-form">
                @csrf
                <div class="modal-body">
                    <div class="form-group">
                        <label for="reason-{{ $application->id }}">驳回理由</label>
                        <textarea class="form-control" id="reason-{{ $application->id }}" name="reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger reject-submit-btn">确认驳回</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach
@endsection

@section('scripts')
<script>
// 处理批准按钮点击
function handleApprove(button) {
    if (!confirm('确定批准延期申请？')) {
        return false;
    }
    
    // 禁用按钮防止重复点击
    button.disabled = true;
    button.innerHTML = '处理中...';
    
    // 禁用驳回按钮
    const rejectBtn = button.closest('.btn-group').querySelector('.reject-btn');
    if (rejectBtn) {
        rejectBtn.disabled = true;
    }
    
    // 提交表单
    button.closest('form').submit();
    
    return true;
}

// 处理驳回表单提交
document.addEventListener('DOMContentLoaded', function() {
    const rejectForms = document.querySelectorAll('.reject-form');
    
    rejectForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('.reject-submit-btn');
            
            // 禁用提交按钮
            submitBtn.disabled = true;
            submitBtn.innerHTML = '处理中...';
            
            // 禁用批准按钮
            const row = form.closest('tr');
            if (row) {
                const approveBtn = row.querySelector('.approve-btn');
                if (approveBtn) {
                    approveBtn.disabled = true;
                }
            }
        });
    });
});
</script>
@endsection