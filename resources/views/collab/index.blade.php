@extends('layouts.app')

@section('title', '协同办公 - ' . $project->name)

@section('content')
@include('project.partials.project-tabs', ['project' => $project, 'activeTab' => 'collab'])
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1"><i class="fa fa-users me-2 text-primary"></i>协同办公</h1>
            <p class="text-muted mb-0">
                项目：{{ $project->name }}（{{ $project->code }}）· 项目组成员可共同编辑文档
            </p>
        </div>
        <div>
            <a href="{{ route('project.collab.create', $project) }}" class="btn btn-primary">
                <i class="fa fa-plus me-2"></i>新建文档
            </a>
        </div>
    </div>

    @if($documents->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fa fa-file-text-o" style="font-size: 48px; color: #c5c9d4;"></i>
                <p class="text-muted mt-3 mb-0">该项目还没有协作文档，点击右上角"新建文档"开始协同编辑。</p>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>文档名称</th>
                            <th>类型</th>
                            <th>大小</th>
                            <th>版本</th>
                            <th>创建人</th>
                            <th>最后编辑人</th>
                            <th>更新时间</th>
                            <th class="text-end">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                        <tr>
                            <td>
                                <i class="fa {{ $doc->extension === 'docx' ? 'fa-file-word-o text-primary' : ($doc->extension === 'xlsx' ? 'fa-file-excel-o text-success' : 'fa-file-powerpoint-o text-danger') }} me-2"></i>
                                <a href="{{ route('project.collab.open', ['project' => $project, 'doc' => $doc]) }}" title="打开协同编辑">
                                    {{ $doc->name }}.{{ $doc->extension }}
                                </a>
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ strtoupper($doc->extension) }}</span></td>
                            <td>{{ $doc->display_size }}</td>
                            <td>v{{ $doc->version }}</td>
                            <td>{{ $doc->creator ? $doc->creator->name : '—' }}</td>
                            <td>{{ $doc->updater ? $doc->updater->name : '—' }}</td>
                            <td>{{ $doc->updated_at->format('Y-m-d H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('project.collab.open', ['project' => $project, 'doc' => $doc]) }}" class="btn btn-sm btn-outline-primary" title="协同编辑">
                                    <i class="fa fa-edit"></i> 编辑
                                </a>
                                <a href="{{ url('collab/download/' . $doc->id . '?k=' . $doc->doc_key) }}" class="btn btn-sm btn-outline-secondary" title="下载">
                                    <i class="fa fa-download"></i>
                                </a>
                                @php
                                    $canDelete = auth()->user()->isAdmin()
                                        || auth()->user()->id === $project->creator_id
                                        || auth()->user()->id === $doc->created_by;
                                @endphp
                                @if($canDelete)
                                <form action="{{ route('project.collab.destroy', ['project' => $project, 'doc' => $doc]) }}" method="POST" class="d-inline" onsubmit="return confirm('确定删除文档《{{ $doc->name }}》吗？删除后不可恢复。')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="删除">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
