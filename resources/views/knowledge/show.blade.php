@extends('layouts.app')

@section('title', $knowledge->title)

@section('head')
<style>
.doc-render h1,.doc-render h2,.doc-render h3 { margin-top: 1rem; }
.doc-render pre { background: #f6f8fa; padding: .75rem; border-radius: .375rem; }
.doc-render table { border-collapse: collapse; width: 100%; }
.doc-render td,.doc-render th { border: 1px solid #dee2e6; padding: .375rem .5rem; }
.doc-tree { list-style: none; padding-left: 0; }
.doc-tree ul { list-style: none; padding-left: 1.25rem; }
.doc-tree .tree-item { display: flex; align-items: center; gap: .5rem; padding: .25rem .5rem; border-radius: .25rem; }
.doc-tree .tree-item:hover { background: #f1f3f5; }
.doc-tree .tree-item.active { background: #e7f1fb; }
</style>
@endsection

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0"><i class="fa fa-book me-2"></i>{{ $knowledge->title }}</h5>
            <small class="text-muted">
                {{ $knowledge->author->name ?? '—' }}
                <span class="badge {{ $knowledge->is_share ? 'bg-success' : 'bg-secondary' }} ms-1">{{ $knowledge->is_share ? '共享' : '个人' }}</span>
                · 阅读 {{ $knowledge->read }} · {{ $knowledge->docs->count() }} 文档
            </small>
        </div>
        <div>
            @if($knowledge->isEditableBy(auth()->id()))
            <a href="{{ route('knowledge.edit', $knowledge) }}" class="btn btn-sm btn-outline-primary">编辑</a>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#docModal">新增文档</button>
            <form action="{{ route('knowledge.destroy', $knowledge) }}" method="POST" class="d-inline" onsubmit="return confirm('确认删除该知识库？')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger">删除</button>
            </form>
            @endif
            <a href="{{ route('knowledge.index') }}" class="btn btn-sm btn-outline-secondary">返回</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            {{-- 文档树 --}}
            <div class="col-md-4">
                <h6 class="text-muted">文档目录</h6>
                @if($rootDocs->isEmpty())
                <p class="text-muted small">暂无文档</p>
                @else
                <ul class="doc-tree">
                    @foreach($rootDocs as $doc)
                    @include('knowledge._tree-item', ['doc' => $doc, 'docs' => $docs])
                    @endforeach
                </ul>
                @endif
            </div>
            {{-- 内容区 --}}
            <div class="col-md-8">
                @php
                    $openId = session('openDoc');
                    $active = $docs->firstWhere('id', $openId);
                @endphp
                @if($active)
                    <div class="border rounded p-3">
                        <h5>{{ $active->title }}
                            <span class="badge bg-secondary ms-1">{{ \App\Models\KnowledgeDoc::typeLabel($active->type) }}</span>
                        </h5>
                        <div class="text-muted small mb-2">阅读 {{ $active->read }} · {{ $active->updated_at->format('Y-m-d H:i') }}</div>
                        @if($active->type == \App\Models\KnowledgeDoc::TYPE_LINK)
                        <p><a href="{{ $active->link }}" target="_blank" rel="noopener">{{ $active->link }}</a></p>
                        @else
                        <div class="doc-render">{!! $active->content ?: '<p class="text-muted">（空文档）</p>' !!}</div>
                        @endif
                        @if($knowledge->isEditableBy(auth()->id()))
                        <hr>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary doc-edit" data-id="{{ $active->id }}"
                                    data-title="{{ $active->title }}" data-type="{{ $active->type }}" data-pid="{{ $active->pid }}"
                                    data-md="{{ $active->md_content }}" data-link="{{ $active->link }}" data-sort="{{ $active->sort }}">编辑</button>
                            <form action="{{ route('knowledge.doc.destroy', [$knowledge, $active]) }}" method="POST" class="d-inline" onsubmit="return confirm('确认删除该文档？')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">删除</button>
                            </form>
                        </div>
                        @endif
                    </div>
                @else
                <div class="text-center py-5 text-muted">
                    <i class="fa fa-book-open fa-3x mb-3"></i>
                    <p>请从左侧目录选择文档查看；如为空可新增文档。</p>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- 新增/编辑文档弹层 --}}
<div class="modal fade" id="docModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form id="docForm" method="POST" action="{{ route('knowledge.doc.store', $knowledge) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalTitle">新增文档</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">类型</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="1">文档</option>
                                <option value="2">章节</option>
                                <option value="3">链接</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">上级章节</label>
                            <select name="pid" class="form-select form-select-sm">
                                <option value="0">（无，作为顶级）</option>
                                @foreach($docs as $d)
                                @if($d->type == \App\Models\KnowledgeDoc::TYPE_CHAPTER)
                                <option value="{{ $d->id }}">{{ $d->title }}</option>
                                @endif
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">标题 <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-sm" required maxlength="200">
                    </div>
                    <div class="mb-2" id="mdWrap">
                        <label class="form-label small">内容（Markdown）</label>
                        <textarea name="md_content" rows="8" class="form-control form-control-sm"></textarea>
                    </div>
                    <div class="mb-2" id="linkWrap" style="display:none">
                        <label class="form-label small">链接 URL</label>
                        <input type="url" name="link" class="form-control form-control-sm" placeholder="https://...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">排序</label>
                        <input type="number" name="sort" class="form-control form-control-sm" value="0" style="width:120px">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var docForm = document.getElementById('docForm');
    var title = document.getElementById('docModalTitle');
    var typeSel = docForm.querySelector('[name=type]');
    var mdWrap = document.getElementById('mdWrap');
    var linkWrap = document.getElementById('linkWrap');
    function toggleType() {
        mdWrap.style.display = (typeSel.value == '3') ? 'none' : '';
        linkWrap.style.display = (typeSel.value == '3') ? '' : 'none';
    }
    typeSel.addEventListener('change', toggleType);

    document.querySelectorAll('.doc-edit').forEach(function (b) {
        b.addEventListener('click', function () {
            typeSel.value = b.getAttribute('data-type');
            docForm.querySelector('[name=title]').value = b.getAttribute('data-title');
            docForm.querySelector('[name=pid]').value = b.getAttribute('data-pid');
            docForm.querySelector('[name=md_content]').value = b.getAttribute('data-md') || '';
            docForm.querySelector('[name=link]').value = b.getAttribute('data-link') || '';
            docForm.querySelector('[name=sort]').value = b.getAttribute('data-sort') || 0;
            docForm.action = '{{ route('knowledge.doc.update', ['knowledge' => $knowledge, 'doc' => '__ID__']) }}'.replace('__ID__', b.getAttribute('data-id'));
            title.textContent = '编辑文档';
            toggleType();
            new bootstrap.Modal(document.getElementById('docModal')).show();
        });
    });

    var newBtn = document.querySelector('[data-bs-target="#docModal"]');
    if (newBtn) newBtn.addEventListener('click', function () {
        docForm.reset();
        docForm.querySelector('[name=type]').value = 1;
        docForm.querySelector('[name=pid]').value = 0;
        docForm.action = '{{ route('knowledge.doc.store', $knowledge) }}';
        title.textContent = '新增文档';
        toggleType();
    });
})();
</script>
@endpush