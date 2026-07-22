<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\Page;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class PageController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = Page::query()->with('regions:id,name');

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
            $page->region_names = $page->regions->pluck('name')->toArray();

            return $page;
        });

        return Inertia::render('Admin/Pages/Pages/Index', [
            'pages' => $pages,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Pages/Create', [
            'regions' => Region::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:pages,slug',
            'content' => 'required|string',
            'is_published' => 'boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'region_ids' => 'nullable|array',
            'region_ids.*' => 'exists:regions,id',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
        ]);

        $page = Page::create($validated);

        // Синхронизировать регионы
        $page->regions()->sync($validated['region_ids'] ?? []);

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

        return $this->redirectAfterSave($request, 'admin.pages.index', 'admin.pages.edit', $page, 'Страница успешно создана');
    }

    public function show(Page $page)
    {
        $page->load('regions:id,name');
        $page->list_image = $page->getFirstMediaUrl('list-item');
        $page->detail_desktop_image = $page->getFirstMediaUrl('detail-item-desktop');
        $page->detail_mobile_image = $page->getFirstMediaUrl('detail-item-mobile');

        return Inertia::render('Admin/Pages/Pages/Show', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'content' => $page->content,
                'is_published' => (bool) $page->is_published,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'list_image' => $page->list_image,
                'detail_desktop_image' => $page->detail_desktop_image,
                'detail_mobile_image' => $page->detail_mobile_image,
                'regions' => $page->regions->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
                'created_at' => $page->created_at?->format('d.m.Y H:i'),
                'updated_at' => $page->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    public function edit(Page $page)
    {
        $page->list_image = $page->getFirstMediaUrl('list-item');
        $page->detail_desktop_image = $page->getFirstMediaUrl('detail-item-desktop');
        $page->detail_mobile_image = $page->getFirstMediaUrl('detail-item-mobile');
        $page->region_ids = $page->regions->pluck('id')->toArray();

        return Inertia::render('Admin/Pages/Pages/Edit', [
            'page' => $page,
            'regions' => Region::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Page $page)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:pages,slug,'.$page->id,
            'content' => 'required|string',
            'is_published' => 'boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'region_ids' => 'nullable|array',
            'region_ids.*' => 'exists:regions,id',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
        ]);

        $page->update($validated);

        // Синхронизировать регионы
        $page->regions()->sync($validated['region_ids'] ?? []);

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

        return $this->redirectAfterSave($request, 'admin.pages.index', 'admin.pages.edit', $page, 'Страница успешно обновлена');
    }

    public function destroy(Page $page)
    {
        $page->delete();

        return redirect()->route('admin.pages.index')->with('success', 'Страница успешно удалена');
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
