<?php

namespace App\Http\Controllers\Admin;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        $query = Banner::query();

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

        return Inertia::render('Admin/Pages/Banners/Index', [
            'banners' => $banners,
            'filters' => $request->only(['search', 'is_active', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Banners/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'linkable_type' => 'nullable|string',
            'linkable_id' => 'nullable|integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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

        return redirect()
            ->route('admin.banners.index')
            ->with('success', 'Баннер успешно создан');
    }

    public function edit(Banner $banner)
    {
        $banner->desktop_image = $banner->getFirstMediaUrl('desktop');
        $banner->mobile_image = $banner->getFirstMediaUrl('mobile');
        
        // Загружаем linkable для отображения в форме
        if ($banner->linkable) {
            $banner->linkable_name = $banner->linkable->title ?? $banner->linkable->name ?? null;
        }

        return Inertia::render('Admin/Pages/Banners/Edit', [
            'banner' => $banner,
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

        return redirect()
            ->route('admin.banners.index')
            ->with('success', 'Баннер успешно обновлен');
    }

    public function destroy(Banner $banner)
    {
        $banner->delete();

        return redirect()
            ->route('admin.banners.index')
            ->with('success', 'Баннер успешно удален');
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
