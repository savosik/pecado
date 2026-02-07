<?php

namespace App\Http\Controllers\Admin;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class NewsController extends Controller
{
    public function index(Request $request)
    {
        $query = News::query()->withCount('tags');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('detailed_description', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $news = $query->paginate($perPage)->withQueryString();

        // Загружаем теги и медиа
        $news->getCollection()->transform(function ($item) {
            $item->tag_list = $item->tags->pluck('name')->toArray();
            $item->main_image = $item->getFirstMediaUrl('main_image');
            return $item;
        });

        return Inertia::render('Admin/Pages/News/Index', [
            'news' => $news,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/News/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:news,slug',
            'detailed_description' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'main_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $newsItem = News::create($validated);

        // Прикрепить теги
        if (!empty($validated['tags'])) {
            $newsItem->attachTags($validated['tags']);
        }

        // Загрузить изображение
        if ($request->hasFile('main_image')) {
            $newsItem->addMediaFromRequest('main_image')->toMediaCollection('main_image');
        }

        return redirect()
            ->route('admin.news.index')
            ->with('success', 'Новость успешно создана');
    }

    public function edit(News $news)
    {
        $news->tag_list = $news->tags->pluck('name')->toArray();
        $news->main_image = $news->getFirstMediaUrl('main_image');

        return Inertia::render('Admin/Pages/News/Edit', [
            'news' => $news,
        ]);
    }

    public function update(Request $request, News $news)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:news,slug,' . $news->id,
            'detailed_description' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'main_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $news->update($validated);

        // Синхронизировать теги
        if (isset($validated['tags'])) {
            $news->syncTags($validated['tags']);
        } else {
            $news->syncTags([]);
        }

        // Обновить изображение
        if ($request->hasFile('main_image')) {
            $news->clearMediaCollection('main_image');
            $news->addMediaFromRequest('main_image')->toMediaCollection('main_image');
        }

        return redirect()
            ->route('admin.news.index')
            ->with('success', 'Новость успешно обновлена');
    }

    public function destroy(News $news)
    {
        $news->delete();

        return redirect()
            ->route('admin.news.index')
            ->with('success', 'Новость успешно удалена');
    }

    public function search(Request $request)
    {
        $query = News::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $news = $query->select('id', 'title', 'slug')
            ->limit(20)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->title,
                    'slug' => $item->slug,
                ];
            });

        return response()->json($news);
    }
}
