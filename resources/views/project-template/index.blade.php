@extends('layouts.app')

@section('title', '项目模板管理')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>项目模板管理</h1>
    <a href="{{ route('project-template.create') }}" class="btn btn-primary">
        <i class="fa fa-plus me-2"></i>创建项目模板
    </a>
</div>

<div class="card">
    <div class="card-body">
        @if($templates->isEmpty())
        <p class="text-center text-muted py-4">暂无项目模板</p>
        @else
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>项目模板名称</th>
                    <th>描述</th>
                    <th>创建人</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($templates as $template)
                <tr>
                    <td>{{ $template->name }}</td>
                    <td>{{ $template->description ?? '-' }}</td>
                    <td>{{ $template->user->name ?? '-' }}</td>
                    <td>{{ $template->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('project-template.edit', $template) }}" class="btn btn-sm btn-primary">
                            <i class="fa fa-edit me-1"></i>编辑
                        </a>
                        <form action="{{ route('project-template.destroy', $template) }}" method="POST" class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除该模板吗？删除后可在回收站中恢复。')">
                                <i class="fa fa-trash me-1"></i>删除
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        {{ $templates->links() }}
        @endif
    </div>
</div>
@endsection