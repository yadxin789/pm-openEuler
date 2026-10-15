{{-- 资料文件分类标签页：普通查看页和管理页共用，操作按钮由 $canManage 控制。 --}}
<ul class="nav nav-tabs mb-3" id="{{ $tabIdPrefix }}Tabs" role="tablist">
    @foreach($categoryFiles as $key => $category)
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                id="{{ $tabIdPrefix }}-{{ $key }}-tab"
                data-bs-toggle="tab"
                data-bs-target="#{{ $tabIdPrefix }}-{{ $key }}"
                type="button"
                role="tab"
                aria-controls="{{ $tabIdPrefix }}-{{ $key }}"
                aria-selected="{{ $loop->first ? 'true' : 'false' }}">
            <i class="fa fa-folder me-1"></i>{{ $category['name'] }}
            <span class="badge bg-primary ms-1">{{ $category['files']->count() }}</span>
        </button>
    </li>
    @endforeach
</ul>

<div class="tab-content" id="{{ $tabIdPrefix }}TabsContent">
    @foreach($categoryFiles as $key => $category)
    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
         id="{{ $tabIdPrefix }}-{{ $key }}"
         role="tabpanel"
         aria-labelledby="{{ $tabIdPrefix }}-{{ $key }}-tab">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fa fa-folder me-2"></i>{{ $category['name'] }} ({{ $category['files']->count() }})</h5>
            </div>
            <div class="card-body">
                @if($category['files']->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                            <tr>
                                <th>文件名</th>
                                <th>版本号</th>
                                <th>权限范围</th>
                                <th>部门</th>
                                <th>上传人</th>
                                <th>上传日期</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($category['files'] as $file)
                            <tr>
                                <td>{{ $file->original_name }}</td>
                                <td>v{{ $file->version }}</td>
                                <td>{{ $file->visibility_name }}</td>
                                <td>{{ $file->department->name }}</td>
                                <td>{{ $file->uploader->name }}</td>
                                <td>{{ $file->created_at->format('Y-m-d') }}</td>
                                <td>
                                    <a href="{{ route('file.preview-department', $file) }}" class="btn btn-sm btn-info" target="_blank">预览</a>
                                    <a href="{{ route('file.download-department', $file) }}" class="btn btn-sm btn-success">下载</a>

                                    @if($canManage)
                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#updateModal{{ $file->id }}">更新</button>
                                    <form action="{{ route('file.delete-department', $file) }}" method="POST" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定删除此文件吗？删除后可在回收站中恢复。')">删除</button>
                                    </form>

                                    <!-- 更新模态框 -->
                                    <div class="modal fade" id="updateModal{{ $file->id }}" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">更新文件</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('file.update-department', $file) }}" method="POST" enctype="multipart/form-data">
                                                    @csrf
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label for="file{{ $file->id }}" class="form-label">选择新文件</label>
                                                            <input type="file" class="form-control" id="file{{ $file->id }}" name="file" required>
                                                            <small class="text-muted">当前版本: v{{ $file->version }}</small>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                        <button type="submit" class="btn btn-primary">确认更新</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <p class="text-muted text-center py-3">暂无文件</p>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>