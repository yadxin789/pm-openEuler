@extends('layouts.app')

@section('title', '变更项目')

@section('content')
<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">变更项目 - {{ $project->name }}</h3>
            </div>
            <div class="card-body">
                @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif
                
                <form action="{{ route('project.update-change', $project) }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <!-- 项目基本信息 -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2">项目基本信息</h5>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">项目名称 <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $project->name) }}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="code" class="form-label">项目编号</label>
                                <input type="text" name="code" id="code" class="form-control" value="{{ $project->code }}" readonly>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="product_name" class="form-label">产品名称 <span class="text-danger">*</span></label>
                                <input type="text" name="product_name" id="product_name" class="form-control" value="{{ old('product_name', $project->product_name) }}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="product_model" class="form-label">产品型号 <span class="text-danger">*</span></label>
                                <input type="text" name="product_model" id="product_model" class="form-control" value="{{ old('product_model', $project->product_model) }}" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="start_date" class="form-label">开始日期 <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="start_date" class="form-control" value="{{ old('start_date', $project->start_date ? $project->start_date->format('Y-m-d') : date('Y-m-d')) }}" required>
                                <small class="text-muted">修改会重新计算所有任务日期</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">项目截止日期（自动计算）</label>
                                <input type="text" class="form-control" value="{{ $project->end_date ? $project->end_date->format('Y-m-d') : '' }}" disabled>
                                <small class="text-muted">将由各部门天数自动计算</small>
                            </div>
                        </div>
                        <!-- 各部门天数 -->
                        <h6 class="mt-3">各部门所需天数</h6>
                        <div class="row mb-3">
                            @foreach($participatingDepts as $dept)
                            @php
                                // 计算原任务天数
                                $deptTasks = $project->tasks()->where('department_id', $dept->id)->orderBy('start_date')->get();
                                $originalDays = 0;
                                if ($deptTasks->count() > 0) {
                                    $firstTask = $deptTasks->first();
                                    $lastTask = $deptTasks->last();
                                    $originalDays = \Carbon\Carbon::parse($firstTask->start_date)->diffInDays(\Carbon\Carbon::parse($lastTask->end_date)) + 1;
                                }
                            @endphp
                            <div class="col-md-4 mb-2">
                                <label class="form-label">{{ $dept->name }} <small>(原{{ $originalDays }}天)</small></label>
                                <input type="number" name="dept_days[{{ $dept->id }}]" class="form-control" value="{{ old('dept_days.' . $dept->id, $originalDays) }}" min="1" required>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    
                    <!-- 变更说明 -->
                    <div class="mb-4">
                        <h5 class="border-bottom pb-2">变更说明</h5>
                        
                        <div class="mb-3">
                            <label for="change_reason" class="form-label">变更原因 <span class="text-danger">*</span></label>
                            <textarea name="change_reason" id="change_reason" class="form-control" rows="3" placeholder="请详细说明本次变更的原因..." required>{{ old('change_reason') }}</textarea>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-2"></i>提交变更
                        </button>
                        <a href="{{ route('project.show', $project) }}" class="btn btn-secondary">
                            <i class="fa fa-times me-2"></i>取消
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
