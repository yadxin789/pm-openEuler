@extends('layouts.app')

@section('title', $knowledge ? '编辑知识库' : '新建知识库')

@section('content')
<div class="card">
    <div class="card-header"><h5 class="mb-0"><i class="fa fa-book me-2"></i>{{ $knowledge ? '编辑知识库' : '新建知识库' }}</h5></div>
    <div class="card-body">
        <form method="POST" action="{{ $knowledge ? route('knowledge.update', $knowledge) : route('knowledge.store') }}">
            @csrf @if($knowledge) @method('PUT') @endif
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">标题 <span class="text-danger">*</span></label>
                <div class="col-sm-9"><input type="text" name="title" class="form-control" value="{{ old('title', $knowledge->title ?? '') }}" required maxlength="200"></div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">分类</label>
                <div class="col-sm-4">
                    <select name="cate_id" class="form-select">
                        <option value="">无分类</option>
                        @foreach($categories as $c)
                        <option value="{{ $c->id }}" {{ old('cate_id', $knowledge->cate_id ?? '') == $c->id ? 'selected' : '' }}>{{ $c->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">可见范围</label>
                <div class="col-sm-4">
                    <div class="form-check form-switch mt-1">
                        <input class="form-check-input" type="checkbox" name="is_share" value="1" id="isShare"
                               {{ old('is_share', $knowledge->is_share ?? 1) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isShare">共享知识（全员可见；关闭则为个人知识，仅自己可见）</label>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">简介</label>
                <div class="col-sm-9"><input type="text" name="desc" class="form-control" value="{{ old('desc', $knowledge->desc ?? '') }}" maxlength="500"></div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">排序</label>
                <div class="col-sm-2"><input type="number" name="sort" class="form-control" value="{{ old('sort', $knowledge->sort ?? 0) }}"></div>
            </div>
            <div class="row">
                <div class="col-sm-9 offset-sm-2">
                    <button class="btn btn-primary">{{ $knowledge ? '保存修改' : '创建' }}</button>
                    <a href="{{ route('knowledge.index') }}" class="btn btn-outline-secondary">取消</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection