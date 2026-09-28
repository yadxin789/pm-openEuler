{{-- 文档树递归项 --}}
<li>
    <div class="tree-item {{ isset($active) && $active && $active->id == $doc->id ? 'active' : '' }}">
        <i class="fa {{ $doc->type == \App\Models\KnowledgeDoc::TYPE_LINK ? 'fa-link' : ($doc->type == \App\Models\KnowledgeDoc::TYPE_CHAPTER ? 'fa-folder' : 'fa-file-text') }} text-muted"></i>
        <a href="{{ route('knowledge.doc.read', [$doc->knowledge_id, $doc]) }}" class="text-decoration-none small">{{ $doc->title }}</a>
        <span class="text-muted small">{{ $doc->read }}</span>
    </div>
    @if($doc->children->count())
    <ul>
        @foreach($doc->children as $child)
        @include('knowledge._tree-item', ['doc' => $child, 'docs' => $docs])
        @endforeach
    </ul>
    @endif
</li>