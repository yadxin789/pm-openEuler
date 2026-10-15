<?php

namespace App\Http\Controllers;

use App\Models\Knowledge;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeDoc;
use App\Support\Markdown;
use App\Helpers\OperationLogHelper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $uid = auth()->id();
        $query = Knowledge::with('author', 'category')->where(function ($q) use ($uid) {
            $q->where('is_share', 1)->orWhere('admin_id', $uid);
        });
        if ($request->filled('keyword')) {
            $query->where('title', 'like', '%' . $request->keyword . '%');
        }
        if ($request->filled('cate_id')) {
            $query->where('cate_id', $request->cate_id);
        }
        if ($request->filled('scope')) {
            $query->where('is_share', $request->scope == 'share' ? 1 : 0)->where('admin_id', $uid);
        }
        $knowledges = $query->withCount('docs')->orderByDesc('sort')->orderByDesc('id')->paginate(12)->withQueryString();
        $categories = KnowledgeCategory::orderBy('sort')->orderBy('id')->get();
        return view('knowledge.index', compact('knowledges', 'categories'));
    }

    public function show(Knowledge $knowledge)
    {
        abort_unless($knowledge->isVisibleTo(auth()->id()), 403, '无权查看该知识库');
        $knowledge->increment('read');
        $docs = $knowledge->docs()->with('children')->orderBy('sort')->orderBy('id')->get();
        $rootDocs = $docs->where('pid', 0)->values();
        return view('knowledge.show', compact('knowledge', 'docs', 'rootDocs'));
    }

    public function create()
    {
        $categories = KnowledgeCategory::orderBy('sort')->orderBy('id')->get();
        return view('knowledge.form', compact('categories'))->with('knowledge', null);
    }

    public function store(Request $request)
    {
        $data = $this->validateKnowledge($request);
        $desc = mb_substr(strip_tags(Markdown::render($data['md_content'] ?? '')), 0, 100);
        Knowledge::create([
            'admin_id' => auth()->id(),
            'cate_id' => $data['cate_id'] ?: null,
            'title' => $data['title'],
            'desc' => $desc ?: ($data['desc'] ?? ''),
            'is_share' => $request->boolean('is_share'),
            'sort' => (int) ($data['sort'] ?? 0),
        ]);
        OperationLogHelper::log('知识库', '创建', "创建知识库「{$data['title']}」");
        return redirect()->route('knowledge.index')->with('success', '知识库已创建');
    }

    public function edit(Knowledge $knowledge)
    {
        abort_unless($knowledge->isEditableBy(auth()->id()), 403);
        $categories = KnowledgeCategory::orderBy('sort')->orderBy('id')->get();
        return view('knowledge.form', compact('categories', 'knowledge'));
    }

    public function update(Request $request, Knowledge $knowledge)
    {
        abort_unless($knowledge->isEditableBy(auth()->id()), 403);
        $data = $this->validateKnowledge($request);
        $knowledge->update([
            'cate_id' => $data['cate_id'] ?: null,
            'title' => $data['title'],
            'desc' => $data['desc'] ?? '',
            'is_share' => $request->boolean('is_share'),
            'sort' => (int) ($data['sort'] ?? 0),
        ]);
        OperationLogHelper::log('知识库', '修改', "修改知识库「{$knowledge->title}」");
        return redirect()->route('knowledge.show', $knowledge)->with('success', '知识库已更新');
    }

    public function destroy(Request $request, Knowledge $knowledge)
    {
        abort_unless($knowledge->isEditableBy(auth()->id()), 403);
        if ($knowledge->docs()->count() > 0) {
            return back()->with('error', '该知识库下存在文档，不可删除');
        }
        OperationLogHelper::log('知识库', '删除', "删除知识库「{$knowledge->title}」");
        $knowledge->delete();
        return redirect()->route('knowledge.index')->with('success', '知识库已删除，可随时在回收站中恢复');
    }

    public function docStore(Request $request, Knowledge $knowledge)
    {
        abort_unless($knowledge->isEditableBy(auth()->id()), 403);
        $data = $this->validateDoc($request);
        $md = $data['md_content'] ?? '';
        KnowledgeDoc::create([
            'knowledge_id' => $knowledge->id,
            'pid' => (int) ($data['pid'] ?? 0),
            'type' => (int) ($data['type'] ?? KnowledgeDoc::TYPE_DOC),
            'title' => $data['title'],
            'md_content' => $md,
            'content' => $md ? Markdown::render($md) : null,
            'link' => $data['link'] ?? null,
            'file_ids' => $data['file_ids'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ]);
        OperationLogHelper::log('知识库', '新增文档', "「{$knowledge->title}」新增文档「{$data['title']}」");
        return redirect()->route('knowledge.show', $knowledge)->with('success', '文档已创建');
    }

    public function docUpdate(Request $request, Knowledge $knowledge, KnowledgeDoc $doc)
    {
        abort_unless($knowledge->isEditableBy(auth()->id()), 403);
        abort_unless($doc->knowledge_id == $knowledge->id, 404);
        $data = $this->validateDoc($request);
        $md = $data['md_content'] ?? '';
        $doc->update([
            'pid' => (int) ($data['pid'] ?? 0),
            'type' => (int) ($data['type'] ?? KnowledgeDoc::TYPE_DOC),
            'title' => $data['title'],
            'md_content' => $md,
            'content' => $md ? Markdown::render($md) : null,
            'link' => $data['link'] ?? null,
            'file_ids' => $data['file_ids'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ]);
        OperationLogHelper::log('知识库', '修改文档', "「{$knowledge->title}」修改文档「{$doc->title}」");
        return redirect()->route('knowledge.show', $knowledge)->with('success', '文档已更新');
    }

    public function docDestroy(Request $request, Knowledge $knowledge, KnowledgeDoc $doc)
    {
        abort_unless($knowledge->isEditableBy(auth()->id()), 403);
        abort_unless($doc->knowledge_id == $knowledge->id, 404);
        if ($doc->children()->count() > 0) {
            return back()->with('error', '该文档存在子文档，不可删除');
        }
        OperationLogHelper::log('知识库', '删除文档', "「{$knowledge->title}」删除文档「{$doc->title}」");
        $doc->delete();
        return redirect()->route('knowledge.show', $knowledge)->with('success', '文档已删除，可随时在回收站中恢复');
    }

    public function docRead(Knowledge $knowledge, KnowledgeDoc $doc)
    {
        abort_unless($knowledge->isVisibleTo(auth()->id()), 403);
        $doc->increment('read');
        return redirect()->route('knowledge.show', $knowledge)->with('openDoc', $doc->id);
    }

    private function validateKnowledge(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:200',
            'cate_id' => 'nullable|integer',
            'desc' => 'nullable|string|max:500',
            'md_content' => 'nullable|string',
            'sort' => 'nullable|integer',
        ]);
    }

    private function validateDoc(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:200',
            'type' => 'required|in:1,2,3',
            'pid' => 'nullable|integer',
            'md_content' => 'nullable|string',
            'link' => 'nullable|url|max:500',
            'file_ids' => 'nullable|string|max:500',
            'sort' => 'nullable|integer',
        ]);
    }
}