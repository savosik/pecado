<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD промоакций с привязкой товаров.
 *
 * @tags Промоакции
 */
class PromotionController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список промоакций.
     *
     * Промоакции с привязанными товарами. Поиск по названию/описанию.
     *
     * @queryParam search string Поиск по названию и описанию
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = Promotion::query()->withCount('products');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowedSorts = ['id', 'name', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (Promotion $item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить одну промоакцию с товарами.
     *
     * Возвращает полную информацию + список привязанных товаров с ценами.
     */
    public function show(Promotion $promotion): JsonResponse
    {
        $promotion->load(['products' => fn ($q) => $q->with('brand', 'media')]);

        $data = $this->format($promotion);
        $data['products'] = $promotion->products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'brand_name' => $p->brand?->name,
            'base_price' => (float) $p->base_price,
            'image_url' => $p->getFirstMediaUrl('main') ?: null,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Создать промоакцию.
     *
     * Можно сразу привязать товары через product_ids.
     * Изображения: list_item, detail_desktop, detail_mobile, images[] (галерея).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'description' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'list_item' => 'nullable|image|max:10240',
            'detail_desktop' => 'nullable|image|max:10240',
            'detail_mobile' => 'nullable|image|max:10240',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
            'images_urls' => 'nullable|array',
            'images_urls.*' => 'url',
        ]);

        DB::beginTransaction();
        try {
            $promotion = Promotion::create([
                'name' => $validated['name'],
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            if (! empty($validated['product_ids'])) {
                $promotion->products()->sync($validated['product_ids']);
            }

            $this->handleMediaUpload($request, $promotion, 'list_item', 'list-item');
            $this->handleMediaUpload($request, $promotion, 'detail_desktop', 'detail-item-desktop');
            $this->handleMediaUpload($request, $promotion, 'detail_mobile', 'detail-item-mobile');
            $this->handleMediaArrayUpload($request, $promotion, 'images', 'gallery');

            DB::commit();

            return response()->json(['data' => $this->format($promotion->fresh())], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Ошибка при создании акции', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Обновить промоакцию.
     *
     * Можно передавать только изменённые поля.
     * Для удаления фото из галереи передайте delete_gallery_ids.
     */
    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'description' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'list_item' => 'nullable|image|max:10240',
            'detail_desktop' => 'nullable|image|max:10240',
            'detail_mobile' => 'nullable|image|max:10240',
            'list_item_url' => 'nullable|url',
            'detail_desktop_url' => 'nullable|url',
            'detail_mobile_url' => 'nullable|url',
            'images' => 'nullable|array',
            'images.*' => 'image|max:10240',
            'images_urls' => 'nullable|array',
            'images_urls.*' => 'url',
            'delete_gallery_ids' => 'nullable|array',
            'delete_gallery_ids.*' => 'integer',
        ]);

        DB::beginTransaction();
        try {
            $promotion->update(collect($validated)->only(['name', 'meta_title', 'meta_description', 'description'])->toArray());

            if (array_key_exists('product_ids', $validated)) {
                $promotion->products()->sync($validated['product_ids'] ?? []);
            }

            $this->handleMediaUpload($request, $promotion, 'list_item', 'list-item', clearFirst: true);
            $this->handleMediaUpload($request, $promotion, 'detail_desktop', 'detail-item-desktop', clearFirst: true);
            $this->handleMediaUpload($request, $promotion, 'detail_mobile', 'detail-item-mobile', clearFirst: true);

            // Удаление из галереи
            if (! empty($validated['delete_gallery_ids'])) {
                foreach ($validated['delete_gallery_ids'] as $mediaId) {
                    $promotion->getMedia('gallery')->firstWhere('id', $mediaId)?->delete();
                }
            }

            $this->handleMediaArrayUpload($request, $promotion, 'images', 'gallery');

            DB::commit();

            return response()->json(['data' => $this->format($promotion->fresh())]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Ошибка при обновлении акции', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Синхронизировать товары акции.
     *
     * POST /api/content/promotions/{promotion}/products
     */
    public function syncProducts(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        $promotion->products()->sync($validated['product_ids']);

        return response()->json([
            'message' => 'Товары акции обновлены',
            'products_count' => $promotion->products()->count(),
        ]);
    }

    /**
     * Удалить промоакцию.
     */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(null, 204);
    }

    private function format(Promotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'meta_title' => $promotion->meta_title,
            'meta_description' => $promotion->meta_description,
            'description' => $promotion->description,
            'products_count' => $promotion->products_count ?? $promotion->products()->count(),
            'images' => $this->getMediaUrls($promotion, ['list-item', 'detail-item-desktop', 'detail-item-mobile']),
            'gallery' => $promotion->getMedia('gallery')->map(fn ($m) => [
                'id' => $m->id,
                'url' => $m->getUrl(),
                'name' => $m->file_name,
            ]),
            'created_at' => $promotion->created_at?->toIso8601String(),
            'updated_at' => $promotion->updated_at?->toIso8601String(),
        ];
    }
}
