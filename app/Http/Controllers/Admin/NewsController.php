<?php

namespace App\Http\Controllers\Admin;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class NewsController extends Controller
{
    use RedirectsAfterSave;

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
            $item->list_image = $item->getFirstMediaUrl('list-item');
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

        $newsItem = News::create($validated);

        // Прикрепить теги
        if (!empty($validated['tags'])) {
            $newsItem->attachTags($validated['tags']);
        }

        // Загрузить изображения
        if ($request->hasFile('list_item')) {
            $newsItem->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $newsItem->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $newsItem->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return $this->redirectAfterSave($request, 'admin.news.index', 'admin.news.edit', $newsItem, 'Новость успешно создана');
    }

    public function edit(News $news)
    {
        $news->tag_list = $news->tags->pluck('name')->toArray();
        $news->list_image = $news->getFirstMediaUrl('list-item');
        $news->detail_desktop_image = $news->getFirstMediaUrl('detail-item-desktop');
        $news->detail_mobile_image = $news->getFirstMediaUrl('detail-item-mobile');
        $news->published_at = $news->published_at?->format('Y-m-d\TH:i');

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

        $news->update($validated);

        // Синхронизировать теги
        if (isset($validated['tags'])) {
            $news->syncTags($validated['tags']);
        } else {
            $news->syncTags([]);
        }

        // Обновить изображения
        if ($request->hasFile('list_item')) {
            $news->clearMediaCollection('list-item');
            $news->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $news->clearMediaCollection('detail-item-desktop');
            $news->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $news->clearMediaCollection('detail-item-mobile');
            $news->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return $this->redirectAfterSave($request, 'admin.news.index', 'admin.news.edit', $news, 'Новость успешно обновлена');
    }

    public function destroy(News $news)
    {
        $news->delete();

        return redirect()->route('admin.news.index')->with('success', 'Новость успешно удалена');
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
