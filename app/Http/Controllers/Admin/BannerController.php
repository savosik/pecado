<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\Banner;
use App\Models\Region;
use App\Support\HomeCache;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class BannerController extends Controller
{
    use RedirectsAfterSave;

    public function index(Request $request)
    {
        $query = Banner::query()->with('regions:id,name');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        // Фильтр по активности
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $banners = $query->paginate($perPage)->withQueryString();

        // Загружаем linkable и медиа для каждого баннера
        $banners->getCollection()->transform(function ($banner) {
            $banner->desktop_image = $banner->getFirstMediaUrl('desktop');
            $banner->mobile_image = $banner->getFirstMediaUrl('mobile');

            // Загружаем linkable и получаем название
            if ($banner->linkable) {
                $banner->linkable_name = $banner->linkable->title ?? $banner->linkable->name ?? null;
            } else {
                $banner->linkable_name = null;
            }

            return $banner;
        });

        // Добавить регионы
        $banners->getCollection()->transform(function ($banner) {
            $banner->region_names = $banner->regions->pluck('name')->toArray();

            return $banner;
        });

        return Inertia::render('Admin/Pages/Banners/Index', [
            'banners' => $banners,
            'filters' => $request->only(['search', 'is_active', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Banners/Create', [
            'regions' => Region::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'region_ids' => 'nullable|array',
            'region_ids.*' => 'exists:regions,id',
            'desktop_image' => 'required|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
            'mobile_image' => 'required|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
        ]);

        $banner = Banner::create($validated);

        // Загрузить изображения через Spatie Media Library
        if ($request->hasFile('desktop_image')) {
            $banner->addMediaFromRequest('desktop_image')->toMediaCollection('desktop');
        }

        if ($request->hasFile('mobile_image')) {
            $banner->addMediaFromRequest('mobile_image')->toMediaCollection('mobile');
        }

        // Синхронизировать регионы
        $banner->regions()->sync($validated['region_ids'] ?? []);

        // Pivot-sync не триггерит Banner::saved — сбрасываем кеш повторно после регионов
        HomeCache::flushBanners();

        return $this->redirectAfterSave($request, 'admin.banners.index', 'admin.banners.edit', $banner, 'Баннер успешно создан');
    }

    public function show(Banner $banner)
    {
        $banner->load('regions:id,name');
        $banner->desktop_image = $banner->getFirstMediaUrl('desktop');
        $banner->mobile_image = $banner->getFirstMediaUrl('mobile');
        if ($banner->linkable) {
            $banner->linkable_name = $banner->linkable->title ?? $banner->linkable->name ?? null;
        }

        return Inertia::render('Admin/Pages/Banners/Show', [
            'banner' => [
                'id' => $banner->id,
                'title' => $banner->title,
                'linkable_type' => $banner->linkable_type,
                'linkable_id' => $banner->linkable_id,
                'linkable_name' => $banner->linkable_name ?? null,
                'is_active' => $banner->is_active,
                'sort_order' => $banner->sort_order,
                'desktop_image' => $banner->desktop_image,
                'mobile_image' => $banner->mobile_image,
                'regions' => $banner->regions->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->toArray(),
                'created_at' => $banner->created_at?->format('d.m.Y H:i'),
                'updated_at' => $banner->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    public function edit(Banner $banner)
    {
        $banner->desktop_image = $banner->getFirstMediaUrl('desktop');
        $banner->mobile_image = $banner->getFirstMediaUrl('mobile');
        $banner->region_ids = $banner->regions->pluck('id')->toArray();

        // Загружаем linkable для отображения в форме
        if ($banner->linkable) {
            $banner->linkable_name = $banner->linkable->title ?? $banner->linkable->name ?? null;
        }

        return Inertia::render('Admin/Pages/Banners/Edit', [
            'banner' => $banner,
            'regions' => Region::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Banner $banner)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'region_ids' => 'nullable|array',
            'region_ids.*' => 'exists:regions,id',
            'desktop_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
            'mobile_image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov|max:10240',
        ]);

        $banner->update($validated);

        // Обновить desktop изображение если загружено новое
        if ($request->hasFile('desktop_image')) {
            $banner->clearMediaCollection('desktop');
            $banner->addMediaFromRequest('desktop_image')->toMediaCollection('desktop');
        }

        // Обновить mobile изображение если загружено новое
        if ($request->hasFile('mobile_image')) {
            $banner->clearMediaCollection('mobile');
            $banner->addMediaFromRequest('mobile_image')->toMediaCollection('mobile');
        }

        // Синхронизировать регионы
        $banner->regions()->sync($validated['region_ids'] ?? []);

        // Pivot-sync не триггерит Banner::saved — сбрасываем кеш повторно после регионов
        HomeCache::flushBanners();

        return $this->redirectAfterSave($request, 'admin.banners.index', 'admin.banners.edit', $banner, 'Баннер успешно обновлен');
    }

    public function destroy(Banner $banner)
    {
        $banner->delete();

        return redirect()->route('admin.banners.index')->with('success', 'Баннер успешно удален');
    }

    public function search(Request $request)
    {
        $query = Banner::query();

        if ($search = $request->input('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $banners = $query->select('id', 'title')
            ->limit(20)
            ->get()
            ->map(function ($banner) {
                return [
                    'id' => $banner->id,
                    'name' => $banner->title,
                ];
            });

        return response()->json($banners);
    }
}
