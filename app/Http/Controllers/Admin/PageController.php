<?php

namespace App\Http\Controllers\Admin;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Illuminate\Support\Str;

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
            $page->main_image = $page->getFirstMediaUrl('main_image');
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
            'main_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $page = Page::create($validated);

        // Загрузить изображение через Spatie Media Library
        if ($request->hasFile('main_image')) {
            $page->addMediaFromRequest('main_image')->toMediaCollection('main_image');
        }

        return redirect()
            ->route('admin.pages.index')
            ->with('success', 'Страница успешно создана');
    }

    public function edit(Page $page)
    {
        $page->main_image = $page->getFirstMediaUrl('main_image');

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
            'main_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $page->update($validated);

        // Обновить изображение если загружено новое
        if ($request->hasFile('main_image')) {
            $page->clearMediaCollection('main_image');
            $page->addMediaFromRequest('main_image')->toMediaCollection('main_image');
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
