@extends('layouts.app')

@section('title', '编辑项目')

@section('content')
<h1 class="mb-4">编辑项目</h1>

<form method="POST" action="{{ route('project.update', $project) }}">
    @csrf
    @method('PUT')
    <div class="row g-3">
        <div class="col-md-2">
            <label for="name" class="form-label">项目名称</label>
            <input type="text" name="name" id="name" class="form-control" value="{{ $project->name }}" required>
        </div>
        <div class="col-md-1">
            <label for="code" class="form-label">项目编码</label>
            <input type="text" name="code" id="code" class="form-control" value="{{ $project->code }}" required>
        </div>
        <div class="col-md-1">
            <label for="version" class="form-label">版本号</label>
            <input type="text" name="version" id="version" class="form-control" value="{{ $project->version }}" required>
        </div>
        <div class="col-md-2">
            <label for="product_name" class="form-label">产品名称</label>
            <input type="text" name="product_name" id="product_name" class="form-control" value="{{ $project->product_name }}" required>
        </div>
        <div class="col-md-2">
            <label for="product_model" class="form-label">产品型号</label>
            <input type="text" name="product_model" id="product_model" class="form-control" value="{{ $project->product_model }}" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">当前状态</label>
            <div class="form-control-static">
                <span class="badge" style="background-color: {{ $project->status->color() }}; color: white;">
                    {{ $project->status->label() }}
                </span>
                <small class="text-muted d-block mt-1">（状态由系统自动管理）</small>
            </div>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">保存</button>
</form>
@endsection