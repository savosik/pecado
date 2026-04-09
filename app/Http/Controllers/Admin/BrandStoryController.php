<?php

namespace App\Http\Controllers\Admin;

use App\Models\Brand;
use App\Models\BrandStory;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class BrandStoryController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = BrandStory::query()->with('brand')->withCount('tags')->with('regions:id,name');

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
        $brandStories = $query->paginate($perPage)->withQueryString();

        // Загружаем теги и медиа для каждой записи
        $brandStories->getCollection()->transform(function ($brandStory) {
            $brandStory->tag_list = $brandStory->tags->pluck('name')->toArray();
            $brandStory->list_image = $brandStory->getFirstMediaUrl('list-item');
            $brandStory->region_names = $brandStory->regions->pluck('name')->toArray();
            return $brandStory;
        });

        return Inertia::render('Admin/Pages/BrandStories/Index', [
            'brandStories' => $brandStories,
            'filters' => $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        $brands = Brand::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/Pages/BrandStories/Create', [
            'brands' => $brands,
            'regions' => Region::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:brand_stories,slug',
            'short_description' => 'required|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'brand_id' => 'required|exists:brands,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'region_ids' => 'nullable|array',
            'region_ids.*' => 'exists:regions,id',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
        ], [], [
            'title' => 'заголовок',
            'slug' => 'slug',
            'short_description' => 'краткое описание',
            'detailed_description' => 'полное описание',
            'is_published' => 'опубликован',
            'published_at' => 'дата публикации',
            'meta_title' => 'meta заголовок',
            'meta_description' => 'meta описание',
            'brand_id' => 'бренд',
            'tags' => 'теги',
            'list_item' => 'изображение для списка',
            'detail_desktop' => 'изображение (десктоп)',
            'detail_mobile' => 'изображение (мобильное)',
        ]);

        $brandStory = BrandStory::create($validated);

        // Прикрепить теги
        // Синхронизировать регионы
        $brandStory->regions()->sync($validated['region_ids'] ?? []);

        if (!empty($validated['tags'])) {
            $brandStory->attachTags($validated['tags']);
        }

        // Загрузить изображения
        if ($request->hasFile('list_item')) {
            $brandStory->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $brandStory->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $brandStory->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return $this->redirectAfterSave($request, 'admin.brand-stories.index', 'admin.brand-stories.edit', $brandStory, 'Статья о бренде успешно создана');
    }

    public function edit(BrandStory $brandStory)
    {
        $brandStory->tag_list = $brandStory->tags->pluck('name')->toArray();
        $brandStory->list_image = $brandStory->getFirstMediaUrl('list-item');
        $brandStory->detail_desktop_image = $brandStory->getFirstMediaUrl('detail-item-desktop');
        $brandStory->detail_mobile_image = $brandStory->getFirstMediaUrl('detail-item-mobile');
        $brandStory->published_at = $brandStory->published_at?->format('Y-m-d\TH:i');
        $brandStory->region_ids = $brandStory->regions->pluck('id')->toArray();

        $brands = Brand::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/Pages/BrandStories/Edit', [
            'brandStory' => $brandStory,
            'brands' => $brands,
            'regions' => Region::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, BrandStory $brandStory)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:brand_stories,slug,' . $brandStory->id,
            'short_description' => 'required|string',
            'detailed_description' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'brand_id' => 'required|exists:brands,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'region_ids' => 'nullable|array',
            'region_ids.*' => 'exists:regions,id',
            'list_item' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_desktop' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
            'detail_mobile' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:20480',
        ], [], [
            'title' => 'заголовок',
            'slug' => 'slug',
            'short_description' => 'краткое описание',
            'detailed_description' => 'полное описание',
            'is_published' => 'опубликован',
            'published_at' => 'дата публикации',
            'meta_title' => 'meta заголовок',
            'meta_description' => 'meta описание',
            'brand_id' => 'бренд',
            'tags' => 'теги',
            'list_item' => 'изображение для списка',
            'detail_desktop' => 'изображение (десктоп)',
            'detail_mobile' => 'изображение (мобильное)',
        ]);

        $brandStory->update($validated);

        // Синхронизировать регионы
        $brandStory->regions()->sync($validated['region_ids'] ?? []);

        // Синхронизировать теги
        if (isset($validated['tags'])) {
            $brandStory->syncTags($validated['tags']);
        } else {
            $brandStory->syncTags([]);
        }

        // Обновить изображения
        if ($request->hasFile('list_item')) {
            $brandStory->clearMediaCollection('list-item');
            $brandStory->addMediaFromRequest('list_item')->toMediaCollection('list-item');
        }
        if ($request->hasFile('detail_desktop')) {
            $brandStory->clearMediaCollection('detail-item-desktop');
            $brandStory->addMediaFromRequest('detail_desktop')->toMediaCollection('detail-item-desktop');
        }
        if ($request->hasFile('detail_mobile')) {
            $brandStory->clearMediaCollection('detail-item-mobile');
            $brandStory->addMediaFromRequest('detail_mobile')->toMediaCollection('detail-item-mobile');
        }

        return $this->redirectAfterSave($request, 'admin.brand-stories.index', 'admin.brand-stories.edit', $brandStory, 'Статья о бренде успешно обновлена');
    }

    public function destroy(BrandStory $brandStory)
    {
        $brandStory->delete();

        return redirect()->route('admin.brand-stories.index')->with('success', 'Статья о бренде успешно удалена');
    }

    public function search(Request $request)
    {
        $query = BrandStory::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $brandStories = $query->select('id', 'title', 'slug')
            ->limit(20)
            ->get()
            ->map(function ($brandStory) {
                return [
                    'id' => $brandStory->id,
                    'name' => $brandStory->title,
                    'slug' => $brandStory->slug,
                ];
            });

        return response()->json($brandStories);
    }
}
