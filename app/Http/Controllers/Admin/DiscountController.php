<?php

namespace App\Http\Controllers\Admin;

use App\Models\Discount;
use App\Models\PartnerSegment;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class DiscountController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of discounts.
     */
    public function index(Request $request): Response
    {
        $query = Discount::query()
            ->withCount(['products', 'users', 'productSegments', 'partnerSegments']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        // Фильтрация по статусу
        if ($request->has('is_posted')) {
            $query->where('is_posted', $request->boolean('is_posted'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'percentage', 'is_posted', 'starts_at', 'ends_at', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $discounts = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Discounts/Index', [
            'discounts' => $discounts,
            'filters' => [
                'search' => $search,
                'is_posted' => $request->input('is_posted'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new discount.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Discounts/Create', [
            'typeOptions' => $this->getTypeOptions(),
        ]);
    }

    /**
     * Store a newly created discount in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:agreement,promotion',
            'percentage' => 'required|numeric|min:0|max:100',
            'external_id' => 'nullable|uuid|unique:discounts,external_id',
            'is_posted' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'product_segment_ids' => 'nullable|array',
            'product_segment_ids.*' => 'exists:product_segments,id',
            'partner_segment_ids' => 'nullable|array',
            'partner_segment_ids.*' => 'exists:partner_segments,id',
        ]);

        DB::beginTransaction();
        try {
            $discount = Discount::create([
                'name' => $validated['name'] ?? null,
                'type' => $validated['type'] ?? null,
                'percentage' => $validated['percentage'],
                'external_id' => $validated['external_id'] ?? null,
                'is_posted' => $validated['is_posted'] ?? false,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
            ]);

            // Привязка товаров
            $discount->products()->sync($validated['product_ids'] ?? []);

            // Привязка партнёров (пользователей)
            $discount->users()->sync($validated['user_ids'] ?? []);

            // US-03 v2: привязка сегментов номенклатуры
            $discount->productSegments()->sync($validated['product_segment_ids'] ?? []);

            // US-03 v2: привязка сегментов партнёров
            $discount->partnerSegments()->sync($validated['partner_segment_ids'] ?? []);

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.discounts.index', 'admin.discounts.edit', $discount, 'Скидка успешно создана');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании скидки: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified discount.
     */
    public function show(Discount $discount): Response
    {
        $discount->load(['products.brand', 'products.media', 'users', 'productSegments', 'partnerSegments']);

        return Inertia::render('Admin/Pages/Discounts/Show', [
            'discount' => [
                'id' => $discount->id,
                'name' => $discount->name,
                'type' => $discount->type,
                'percentage' => $discount->percentage,
                'external_id' => $discount->external_id,
                'is_posted' => $discount->is_posted,
                'starts_at' => $discount->starts_at?->format('d.m.Y H:i'),
                'ends_at' => $discount->ends_at?->format('d.m.Y H:i'),
                'created_at' => $discount->created_at?->format('d.m.Y H:i'),
                'updated_at' => $discount->updated_at?->format('d.m.Y H:i'),
                'products' => $discount->products->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'brand_name' => $product->brand?->name,
                        'base_price' => $product->base_price,
                        'image_url' => $product->getFirstMediaUrl('main'),
                    ];
                }),
                'users' => $discount->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->full_name,
                        'email' => $user->email,
                    ];
                }),
                'product_segments' => $discount->productSegments->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'uuid' => $s->uuid,
                ]),
                'partner_segments' => $discount->partnerSegments->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'uuid' => $s->uuid,
                ]),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified discount.
     */
    public function edit(Discount $discount): Response
    {
        $discount->load(['products.media', 'products.brand', 'users', 'productSegments', 'partnerSegments']);

        return Inertia::render('Admin/Pages/Discounts/Edit', [
            'typeOptions' => $this->getTypeOptions(),
            'discount' => [
                'id' => $discount->id,
                'name' => $discount->name,
                'type' => $discount->type,
                'percentage' => $discount->percentage,
                'external_id' => $discount->external_id,
                'is_posted' => $discount->is_posted,
                'starts_at' => $discount->starts_at?->format('Y-m-d\TH:i'),
                'ends_at' => $discount->ends_at?->format('Y-m-d\TH:i'),
                'products' => $discount->products->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'brand_name' => $p->brand?->name,
                    'image_url' => $p->getFirstMediaUrl('main'),
                ]),
                'users' => $discount->users->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->full_name,
                    'email' => $u->email,
                ]),
                'product_segments' => $discount->productSegments->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'uuid' => $s->uuid,
                ]),
                'partner_segments' => $discount->partnerSegments->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'uuid' => $s->uuid,
                ]),
            ],
        ]);
    }

    /**
     * Update the specified discount in storage.
     */
    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:agreement,promotion',
            'percentage' => 'required|numeric|min:0|max:100',
            'external_id' => 'nullable|uuid|unique:discounts,external_id,' . $discount->id,
            'is_posted' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'product_segment_ids' => 'nullable|array',
            'product_segment_ids.*' => 'exists:product_segments,id',
            'partner_segment_ids' => 'nullable|array',
            'partner_segment_ids.*' => 'exists:partner_segments,id',
        ]);

        DB::beginTransaction();
        try {
            $discount->update([
                'name' => $validated['name'] ?? null,
                'type' => $validated['type'] ?? null,
                'percentage' => $validated['percentage'],
                'external_id' => $validated['external_id'] ?? null,
                'is_posted' => $validated['is_posted'] ?? false,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
            ]);

            // Синхронизация товаров
            $discount->products()->sync($validated['product_ids'] ?? []);

            // Синхронизация партнёров
            $discount->users()->sync($validated['user_ids'] ?? []);

            // US-03 v2: синхронизация сегментов номенклатуры
            $discount->productSegments()->sync($validated['product_segment_ids'] ?? []);

            // US-03 v2: синхронизация сегментов партнёров
            $discount->partnerSegments()->sync($validated['partner_segment_ids'] ?? []);

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.discounts.index', 'admin.discounts.edit', $discount, 'Скидка успешно обновлена');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении скидки: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified discount from storage.
     */
    public function destroy(Discount $discount): RedirectResponse
    {
        try {
            $discount->delete();

            return redirect()->route('admin.discounts.index')->with('success', 'Скидка успешно удалена');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'Ошибка при удалении скидки: ' . $e->getMessage()]);
        }
    }

    /**
     * Search users for async selector.
     */
    public function searchUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');
        
        $users = User::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('surname', 'like', "%{$query}%")
                  ->orWhere('patronymic', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->select('id', 'name', 'surname', 'patronymic', 'email')
            ->orderBy('surname')
            ->limit(20)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'label' => "{$user->full_name} ({$user->email})",
                ];
            });
            
        return response()->json($users);
    }

    /**
     * US-03 v2: Поиск сегментов номенклатуры для async selector.
     */
    public function searchProductSegments(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');

        $segments = ProductSegment::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('uuid', 'like', "%{$query}%");
            })
            ->select('id', 'uuid', 'name')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'uuid' => $s->uuid,
                'label' => $s->name,
            ]);

        return response()->json($segments);
    }

    /**
     * US-03 v2: Поиск сегментов партнёров для async selector.
     */
    public function searchPartnerSegments(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');

        $segments = PartnerSegment::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('uuid', 'like', "%{$query}%");
            })
            ->select('id', 'uuid', 'name')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'uuid' => $s->uuid,
                'label' => $s->name,
            ]);

        return response()->json($segments);
    }

    /**
     * Get type options for selects.
     */
    private function getTypeOptions(): array
    {
        return [
            ['value' => '', 'label' => 'Не указан'],
            ['value' => 'agreement', 'label' => 'Соглашение'],
            ['value' => 'promotion', 'label' => 'Акция'],
        ];
    }
}
