<?php

namespace App\Http\Controllers\Admin;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class PageController extends Controller
{
    public function index(Request $request)
    {
        $query = Page::query();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $pages = $query->paginate($perPage)->withQueryString();

        // Загружаем медиа для каждой страницы
        $pages->getCollection()->transform(function ($page) {
            $page->list_image = $page->getFirstMediaUrl('list-item');
            return $page;
        });

        return Inertia::render('Admin/Pages/Pages/Index', [
            'pages' => $pages,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Pages/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:pages,slug',
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:5120',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:5120',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:5120',
        ]);

        $page = Page::create($validated);

        // Загрузить изображения
        if ($request->hasFile('list_item')) {
            $page->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $page->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $page->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Страница успешно создана');
    }

    public function edit(Page $page)
    {
        $page->list_image = $page->getFirstMediaUrl('list-item');
        $page->detail_desktop_image = $page->getFirstMediaUrl('detail-item-desktop');
        $page->detail_mobile_image = $page->getFirstMediaUrl('detail-item-mobile');

        return Inertia::render('Admin/Pages/Pages/Edit', [
            'page' => $page,
        ]);
    }

    public function update(Request $request, Page $page)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:pages,slug,' . $page->id,
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:5120',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:5120',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:5120',
        ]);

        $page->update($validated);

        // Обновить изображения
        if ($request->hasFile('list_item')) {
            $page->clearMediaCollection('list-item');
            $page->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $page->clearMediaCollection('detail-item-desktop');
            $page->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $page->clearMediaCollection('detail-item-mobile');
            $page->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Страница успешно обновлена');
    }

    public function destroy(Page $page)
    {
        $page->delete();

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Страница успешно удалена');
    }

    public function search(Request $request)
    {
        $query = Page::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $pages = $query->select('id', 'title', 'slug')
            ->limit(20)
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'name' => $page->title,
                    'slug' => $page->slug,
                ];
            });

        return response()->json($pages);
    }
}
