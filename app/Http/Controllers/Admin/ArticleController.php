<?php

namespace App\Http\Controllers\Admin;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
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
            $article->main_image = $article->getFirstMediaUrl('main_image');
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
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'main_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $article = Article::create($validated);

        // Прикрепить теги
        if (!empty($validated['tags'])) {
            $article->attachTags($validated['tags']);
        }

        // Загрузить изображение через Spatie Media Library
        if ($request->hasFile('main_image')) {
            $article->addMediaFromRequest('main_image')->toMediaCollection('main_image');
        }

        return redirect()
            ->route('admin.articles.index')
            ->with('success', 'Статья успешно создана');
    }

    public function edit(Article $article)
    {
        $article->tag_list = $article->tags->pluck('name')->toArray();
        $article->main_image = $article->getFirstMediaUrl('main_image');

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
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'main_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $article->update($validated);

        // Синхронизировать теги
        if (isset($validated['tags'])) {
            $article->syncTags($validated['tags']);
        } else {
            $article->syncTags([]);
        }

        // Обновить изображение если загружено новое
        if ($request->hasFile('main_image')) {
            $article->clearMediaCollection('main_image');
            $article->addMediaFromRequest('main_image')->toMediaCollection('main_image');
        }

        return redirect()
            ->route('admin.articles.index')
            ->with('success', 'Статья успешно обновлена');
    }

    public function destroy(Article $article)
    {
        $article->delete();

        return redirect()
            ->route('admin.articles.index')
            ->with('success', 'Статья успешно удалена');
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
