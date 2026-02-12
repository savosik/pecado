<?php

namespace App\Http\Controllers\Admin;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class ArticleController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = Article::query()->withCount('tags');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('detailed_description', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $articles = $query->paginate($perPage)->withQueryString();

        // Загружаем теги и медиа для каждой статьи
        $articles->getCollection()->transform(function ($article) {
            $article->tag_list = $article->tags->pluck('name')->toArray();
            $article->list_image = $article->getFirstMediaUrl('list-item');
            return $article;
        });

        return Inertia::render('Admin/Pages/Articles/Index', [
            'articles' => $articles,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Articles/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:articles,slug',
            'short_description' => 'required|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
        ]);

        $article = Article::create($validated);

        // Прикрепить теги
        if (!empty($validated['tags'])) {
            $article->attachTags($validated['tags']);
        }

        // Загрузить изображения
        if ($request->hasFile('list_item')) {
            $article->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $article->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $article->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return $this->redirectAfterSave($request, 'admin.articles.index', 'admin.articles.edit', $article, 'Статья успешно создана');
    }

    public function edit(Article $article)
    {
        $article->tag_list = $article->tags->pluck('name')->toArray();
        $article->list_image = $article->getFirstMediaUrl('list-item');
        $article->detail_desktop_image = $article->getFirstMediaUrl('detail-item-desktop');
        $article->detail_mobile_image = $article->getFirstMediaUrl('detail-item-mobile');
        $article->published_at = $article->published_at?->format('Y-m-d\TH:i');

        return Inertia::render('Admin/Pages/Articles/Edit', [
            'article' => $article,
        ]);
    }

    public function update(Request $request, Article $article)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:articles,slug,' . $article->id,
            'short_description' => 'required|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
        ]);

        $article->update($validated);

        // Синхронизировать теги
        if (isset($validated['tags'])) {
            $article->syncTags($validated['tags']);
        } else {
            $article->syncTags([]);
        }

        // Обновить изображения
        if ($request->hasFile('list_item')) {
            $article->clearMediaCollection('list-item');
            $article->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $article->clearMediaCollection('detail-item-desktop');
            $article->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $article->clearMediaCollection('detail-item-mobile');
            $article->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return $this->redirectAfterSave($request, 'admin.articles.index', 'admin.articles.edit', $article, 'Статья успешно обновлена');
    }

    public function destroy(Article $article)
    {
        $article->delete();

        return redirect()->route('admin.articles.index')->with('success', 'Статья успешно удалена');
    }

    public function search(Request $request)
    {
        $query = Article::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $articles = $query->select('id', 'title', 'slug')
            ->limit(20)
            ->get()
            ->map(function ($article) {
                return [
                    'id' => $article->id,
                    'name' => $article->title,
                    'slug' => $article->slug,
                ];
            });

        return response()->json($articles);
    }
}
