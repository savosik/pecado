<?php

namespace App\Http\Controllers\Admin;

use App\Models\Promotion;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PromotionController extends AdminController
{
    /**
     * Display a listing of promotions.
     */
    public function index(Request $request): Response
    {
        $query = Promotion::query()->withCount('products');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $promotions = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Promotions/Index', [
            'promotions' => $promotions,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new promotion.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Promotions/Create');
    }

    /**
     * Store a newly created promotion in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'description' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'main_image' => 'nullable|image|max:10240',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
        ]);

        DB::beginTransaction();
        try {
            $promotion = Promotion::create([
                'name' => $validated['name'],
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            // Привязка товаров
            if (!empty($validated['product_ids'])) {
                $promotion->products()->sync($validated['product_ids']);
            }

            // Загрузка медиафайлов
            if ($request->hasFile('main_image')) {
                $promotion->addMedia($request->file('main_image'))
                    ->toMediaCollection('main');
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $promotion->addMedia($image)->toMediaCollection('gallery');
                }
            }

            DB::commit();

            return redirect()
                ->route('admin.promotions.index')
                ->with('success', 'Акция успешно создана');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании акции: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified promotion.
     */
    public function show(Promotion $promotion): Response
    {
        $promotion->load(['products' => function ($query) {
            $query->with('brand', 'media');
        }]);

        return Inertia::render('Admin/Pages/Promotions/Show', [
            'promotion' => [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'meta_title' => $promotion->meta_title,
                'meta_description' => $promotion->meta_description,
                'description' => $promotion->description,
                'created_at' => $promotion->created_at?->format('d.m.Y H:i'),
                'updated_at' => $promotion->updated_at?->format('d.m.Y H:i'),
                'main_image_url' => $promotion->getFirstMediaUrl('main'),
                'gallery_urls' => $promotion->getMedia('gallery')->map(fn ($media) => $media->getUrl()),
                'products' => $promotion->products->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'brand_name' => $product->brand?->name,
                        'image_url' => $product->getFirstMediaUrl('main'),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified promotion.
     */
    public function edit(Promotion $promotion): Response
    {
        $promotion->load('products', 'media');

        return Inertia::render('Admin/Pages/Promotions/Edit', [
            'promotion' => [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'meta_title' => $promotion->meta_title,
                'meta_description' => $promotion->meta_description,
                'description' => $promotion->description,
                'product_ids' => $promotion->products->pluck('id'),
                'main_image_url' => $promotion->getFirstMediaUrl('main'),
                'main_image_id' => $promotion->getFirstMedia('main')?->id,
                'gallery_images' => $promotion->getMedia('gallery')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'name' => $media->file_name,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Update the specified promotion in storage.
     */
    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'description' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'main_image' => 'nullable|image|max:10240',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
            'delete_main_image' => 'nullable|boolean',
            'delete_gallery_ids' => 'nullable|array',
            'delete_gallery_ids.*' => 'integer',
        ]);

        DB::beginTransaction();
        try {
            $promotion->update([
                'name' => $validated['name'],
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            // Синхронизация товаров
            $promotion->products()->sync($validated['product_ids'] ?? []);

            // Удаление главного изображения
            if (!empty($validated['delete_main_image'])) {
                $promotion->clearMediaCollection('main');
            }

            // Загрузка нового главного изображения
            if ($request->hasFile('main_image')) {
                $promotion->clearMediaCollection('main');
                $promotion->addMedia($request->file('main_image'))
                    ->toMediaCollection('main');
            }

            // Удаление изображений галереи
            if (!empty($validated['delete_gallery_ids'])) {
                foreach ($validated['delete_gallery_ids'] as $mediaId) {
                    $media = $promotion->getMedia('gallery')->firstWhere('id', $mediaId);
                    $media?->delete();
                }
            }

            // Загрузка новых изображений галереи
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $promotion->addMedia($image)->toMediaCollection('gallery');
                }
            }

            DB::commit();

            return redirect()
                ->route('admin.promotions.index')
                ->with('success', 'Акция успешно обновлена');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении акции: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified promotion from storage.
     */
    public function destroy(Promotion $promotion): RedirectResponse
    {
        try {
            $promotion->delete();

            return redirect()
                ->route('admin.promotions.index')
                ->with('success', 'Акция успешно удалена');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'Ошибка при удалении акции: ' . $e->getMessage()]);
        }
    }

    /**
     * Delete media from promotion.
     */
    public function deleteMedia(Request $request, Promotion $promotion): RedirectResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $promotion->media()->find($validated['media_id']);
        
        if ($media) {
            $media->delete();
            return redirect()->back()->with('success', 'Медиафайл успешно удалён');
        }

        return redirect()->back()->withErrors(['error' => 'Медиафайл не найден']);
    }
}
