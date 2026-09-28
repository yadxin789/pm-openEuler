@extends('layouts.app')

@section('title', '批量导入用户')

@section('content')
<h1 class="mb-4">批量导入用户</h1>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">
                <strong>上传 CSV 文件</strong>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.users.import') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="csv_file" class="form-label">选择 CSV 文件</label>
                        <input type="file" class="form-control @error('csv_file') is-invalid @enderror"
                               id="csv_file" name="csv_file" accept=".csv" required>
                        @error('csv_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">仅支持 .csv 格式，UTF-8 编码</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> 开始导入
                    </button>
                    <a href="{{ route('admin.users') }}" class="btn btn-secondary ms-2">返回</a>
                </form>
            </div>
        </div>

        @if(session('import_result'))
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <strong>导入结果</strong>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="fs-2 text-success">{{ session('import_result')['success'] }}</div>
                            <div class="text-muted">成功</div>
                        </div>
                        <div class="col-4">
                            <div class="fs-2 text-warning">{{ session('import_result')['skipped'] }}</div>
                            <div class="text-muted">跳过（已存在）</div>
                        </div>
                        <div class="col-4">
                            <div class="fs-2 text-danger">{{ session('import_result')['failed'] }}</div>
                            <div class="text-muted">失败</div>
                        </div>
                    </div>
                    @if(!empty(session('import_result')['errors']))
                        <hr>
                        <h6>错误详情：</h6>
                        <ul class="text-danger small">
                            @foreach(session('import_result')['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <strong>CSV 格式说明</strong>
            </div>
            <div class="card-body">
                <p class="text-muted">CSV 文件应包含以下列（按顺序，不含表头）：</p>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>列</th>
                            <th>字段</th>
                            <th>示例</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1</td>
                            <td>用户名</td>
                            <td>zhangsan</td>
                        </tr>
                        <tr>
                            <td>2</td>
                            <td>密码</td>
                            <td>abc123</td>
                        </tr>
                        <tr>
                            <td>3</td>
                            <td>姓名</td>
                            <td>张三</td>
                        </tr>
                        <tr>
                            <td>4</td>
                            <td>部门ID</td>
                            <td>1</td>
                        </tr>
                        <tr>
                            <td>5</td>
                            <td>角色ID</td>
                            <td>3</td>
                        </tr>
                    </tbody>
                </table>

                <h6 class="mt-3">当前可用的部门和角色：</h6>
                <p class="mb-1"><strong>部门：</strong></p>
                <ul class="small">
                    @foreach($departments as $dept)
                        <li>{{ $dept->id }} — {{ $dept->name }}</li>
                    @endforeach
                </ul>
                <p class="mb-1"><strong>角色：</strong></p>
                <ul class="small">
                    @foreach($roles as $role)
                        <li>{{ $role->id }} — {{ $role->name }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
