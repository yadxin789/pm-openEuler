@extends('layouts.app')

@php use Illuminate\Support\Facades\Storage; @endphp

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">文件管理</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>文件名</th>
                                    <th>所属项目</th>
                                    <th>所属任务</th>
                                    <th>上传人</th>
                                    <th>版本</th>
                                    <th>上传时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($files as $file)
                                <tr>
                                    <td>
                                        <i class="fa fa-file-text-o me-2"></i>
                                        {{ $file->original_name }}
                                    </td>
                                    <td>
                                        @if($file->task && $file->task->project)
                                        {{ $file->task->project->name }}
                                        @else
                                        -
                                        @endif
                                    </td>
                                    <td>
                                        @if($file->task)
                                        {{ $file->task->name }}
                                        @else
                                        -
                                        @endif
                                    </td>
                                    <td>{{ $file->user->name ?? '-' }}</td>
                                    <td>{{ $file->version }}</td>
                                    <td>{{ $file->created_at->format('Y-m-d H:i') }}</td>
                                    <td>
                                        <a href="{{ route('file.download', $file) }}" class="btn btn-sm btn-primary">
                                            <i class="fa fa-download"></i> 下载
                                        </a>
                                        @if(\App\Models\WecomConfig::active())
                                        <button type="button" class="btn btn-sm btn-outline-success send-wecom-btn"
                                                data-path="{{ Storage::disk('file_repository')->path($file->path) }}"
                                                data-name="{{ $file->original_name }}"
                                                data-type="{{ in_array(strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp','bmp']) ? 'image' : 'file' }}">
                                            <i class="fab fa-weixin"></i> 发送企微
                                        </button>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $files->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 发送到企微 Modal --}}
@include('wecom.partials.send-modal-placeholder')

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.send-wecom-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var path = this.dataset.path;
            var name = this.dataset.name;
            var type = this.dataset.type;
            document.querySelector('#sendToWecomModal input[name="file_path"]').value = path;
            document.querySelector('#sendToWecomModal input[name="file_name"]').value = name;
            document.querySelector('#sendToWecomModal input[name="file_type"]').value = type;
            var modal = new bootstrap.Modal(document.getElementById('sendToWecomModal'));
            modal.show();
        });
    });
});
</script>
@endsection