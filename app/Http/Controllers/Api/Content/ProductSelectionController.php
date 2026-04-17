<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\Content\Traits\HandlesMediaUpload;
use App\Http\Controllers\Controller;
use App\Http\Controllers\User\ProductSelectionController as UserProductSelectionController;
use App\Models\ProductSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRUD подборок товаров с привязкой товаров и featured.
 *
 * @tags Подборки товаров
 */
class ProductSelectionController extends Controller
{
    use HandlesMediaUpload;

    /**
     * Список подборок товаров.
     *
     * Курированные подборки с кол-вом товаров и featured. Пример: "Лучшее за неделю", "Новинки апреля".
     *
     * @queryParam search string Поиск по названию и описанию
     * @queryParam per_page integer Записей на странице (5–100). Default: 15
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductSelection::query()->withCount(['products', 'featuredProducts']);

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
            'data' => $paginated->getCollection()->map(fn ($item) => $this->format($item)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Получить одну подборку с товарами.
     *
     * Возвращает полную информацию + список товаров с признаком featured.
     */
    public function show(ProductSelection $productSelection): JsonResponse
    {
        $productSelection->load(['products' => fn ($q) => $q->with('brand', 'media')]);

        $data = $this->format($productSelection);
        $data['products'] = $productSelection->products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'brand_name' => $p->brand?->name,
            'base_price' => (float) $p->base_price,
            'image_url' => $p->getFirstMediaUrl('main') ?: null,
            'featured' => (bool) $p->pivot->featured,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Создать подборку товаров.
     *
     * Можно сразу привязать товары (product_ids) и выделить featured (featured_ids).
     * Slug генерируется автоматически если не указан.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:product_selections,slug',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'show_on_home' => 'nullable|boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'featured_ids' => 'nullable|array',
            'featured_ids.*' => 'exists:products,id',
            'desktop_image' => 'nullable|image|max:10240',
            'mobile_image' => 'nullable|image|max:10240',
            'desktop_image_url' => 'nullable|url',
            'mobile_image_url' => 'nullable|url',
        ]);

        DB::beginTransaction();
        try {
            $selection = ProductSelection::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Str::slug($validated['name']),
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'] ?? null,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'show_on_home' => $validated['show_on_home'] ?? false,
            ]);

            $this->syncProducts($selection, $validated);

            $this->handleMediaUpload($request, $selection, 'desktop_image', 'desktop');
            $this->handleMediaUpload($request, $selection, 'mobile_image', 'mobile');

            DB::commit();
            UserProductSelectionController::clearHomeCache();

            return response()->json(['data' => $this->format($selection->fresh())], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Ошибка при создании подборки', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Обновить подборку товаров.
     *
     * Можно передавать только изменённые поля.
     */
    public function update(Request $request, ProductSelection $productSelection): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|string|unique:product_selections,slug,'.$productSelection->id,
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'show_on_home' => 'nullable|boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'featured_ids' => 'nullable|array',
            'featured_ids.*' => 'exists:products,id',
            'desktop_image' => 'nullable|image|max:10240',
            'mobile_image' => 'nullable|image|max:10240',
            'desktop_image_url' => 'nullable|url',
            'mobile_image_url' => 'nullable|url',
        ]);

        DB::beginTransaction();
        try {
            $productSelection->update(collect($validated)->only([
                'name', 'slug', 'short_description', 'description',
                'meta_title', 'meta_description', 'show_on_home',
            ])->toArray());

            if (array_key_exists('product_ids', $validated)) {
                $this->syncProducts($productSelection, $validated);
            }

            $this->handleMediaUpload($request, $productSelection, 'desktop_image', 'desktop', clearFirst: true);
            $this->handleMediaUpload($request, $productSelection, 'mobile_image', 'mobile', clearFirst: true);

            DB::commit();
            UserProductSelectionController::clearHomeCache();

            return response()->json(['data' => $this->format($productSelection->fresh())]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Ошибка при обновлении подборки', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Синхронизировать товары подборки.
     *
     * POST /api/content/product-selections/{productSelection}/products
     */
    public function syncProductsEndpoint(Request $request, ProductSelection $productSelection): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'featured_ids' => 'nullable|array',
            'featured_ids.*' => 'exists:products,id',
        ]);

        $this->syncProducts($productSelection, $validated);
        UserProductSelectionController::clearHomeCache();

        return response()->json([
            'message' => 'Товары подборки обновлены',
            'products_count' => $productSelection->products()->count(),
        ]);
    }

    /**
     * Удалить подборку.
     */
    public function destroy(ProductSelection $productSelection): JsonResponse
    {
        $productSelection->delete();
        UserProductSelectionController::clearHomeCache();

        return response()->json(null, 204);
    }

    private function syncProducts(ProductSelection $selection, array $data): void
    {
        $productIds = $data['product_ids'] ?? [];
        $featuredIds = $data['featured_ids'] ?? [];
        $syncData = [];

        foreach ($productIds as $productId) {
            $syncData[$productId] = ['featured' => in_array($productId, $featuredIds)];
        }

        $selection->products()->sync($syncData);
    }

    private function format(ProductSelection $selection): array
    {
        return [
            'id' => $selection->id,
            'name' => $selection->name,
            'slug' => $selection->slug,
            'short_description' => $selection->short_description,
            'description' => $selection->description,
            'meta_title' => $selection->meta_title,
            'meta_description' => $selection->meta_description,
            'show_on_home' => (bool) $selection->show_on_home,
            'products_count' => $selection->products_count ?? $selection->products()->count(),
            'featured_products_count' => $selection->featured_products_count ?? 0,
            'images' => [
                'desktop' => $selection->getFirstMediaUrl('desktop') ?: null,
                'mobile' => $selection->getFirstMediaUrl('mobile') ?: null,
            ],
            'created_at' => $selection->created_at?->toIso8601String(),
            'updated_at' => $selection->updated_at?->toIso8601String(),
        ];
    }
}
