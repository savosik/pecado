<?php

namespace App\Http\Controllers\Admin;

use App\Models\Segment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class SegmentController extends AdminController
{
    /**
     * Display a listing of the segments.
     */
    public function index(Request $request): Response
    {
        $query = Segment::query()->with(['media']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $segments = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Segments/Index', [
            'segments' => $segments,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new segment.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Segments/Create');
    }

    /**
     * Store a newly created segment in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'desktop_image' => 'nullable|image|max:5120',
            'mobile_image' => 'nullable|image|max:2048',
        ]);

        $segment = Segment::create($validated);

        if ($request->hasFile('desktop_image')) {
            $segment->addMediaFromRequest('desktop_image')
                ->toMediaCollection('desktop');
        }

        if ($request->hasFile('mobile_image')) {
            $segment->addMediaFromRequest('mobile_image')
                ->toMediaCollection('mobile');
        }

        return redirect()
            ->route('admin.segments.index')
            ->with('success', 'Сегмент успешно создан');
    }

    /**
     * Show the form for editing the specified segment.
     */
    public function edit(Segment $segment): Response
    {
        $segment->load(['media', 'products.media']);
        
        $products = $segment->products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'price' => $product->base_price,
            ];
        });

        return Inertia::render('Admin/Pages/Segments/Edit', [
            'segment' => [
                'id' => $segment->id,
                'name' => $segment->name,
                'meta_title' => $segment->meta_title,
                'meta_description' => $segment->meta_description,
                'desktop_image_url' => $segment->getFirstMediaUrl('desktop'),
                'mobile_image_url' => $segment->getFirstMediaUrl('mobile'),
                'products' => $products,
            ],
        ]);
    }

    /**
     * Update the specified segment in storage.
     */
    public function update(Request $request, Segment $segment): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'desktop_image' => 'nullable|image|max:5120',
            'mobile_image' => 'nullable|image|max:2048',
            'products' => 'nullable|array',
            'products.*' => 'exists:products,id',
        ]);

        $segment->update($validated);

        if ($request->hasFile('desktop_image')) {
            $segment->addMediaFromRequest('desktop_image')
                ->toMediaCollection('desktop');
        }

        if ($request->hasFile('mobile_image')) {
            $segment->addMediaFromRequest('mobile_image')
                ->toMediaCollection('mobile');
        }

        // Синхронизация товаров
        if (isset($validated['products'])) {
            $segment->products()->sync($validated['products']);
        } else {
            $segment->products()->detach();
        }

        return redirect()
            ->route('admin.segments.index')
            ->with('success', 'Сегмент успешно обновлен');
    }

    /**
     * Remove the specified segment from storage.
     */
    public function destroy(Segment $segment): RedirectResponse
    {
        $segment->delete();

        return redirect()
            ->route('admin.segments.index')
            ->with('success', 'Сегмент успешно удален');
    }
}
