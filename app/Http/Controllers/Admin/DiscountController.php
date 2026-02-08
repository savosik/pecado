<?php

namespace App\Http\Controllers\Admin;

use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class DiscountController extends AdminController
{
    /**
     * Display a listing of discounts.
     */
    public function index(Request $request): Response
    {
        $query = Discount::query()
            ->withCount(['products', 'users']);

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
        
        $allowedSortFields = ['id', 'name', 'percentage', 'is_posted', 'created_at', 'updated_at'];
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
        return Inertia::render('Admin/Pages/Discounts/Create');
    }

    /**
     * Store a newly created discount in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'percentage' => 'required|numeric|min:0|max:100',
            'external_id' => 'nullable|uuid|unique:discounts,external_id',
            'is_posted' => 'boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            $discount = Discount::create([
                'name' => $validated['name'] ?? null,
                'percentage' => $validated['percentage'],
                'external_id' => $validated['external_id'] ?? null,
                'is_posted' => $validated['is_posted'] ?? false,
            ]);

            // Привязка товаров
            if (!empty($validated['product_ids'])) {
                $discount->products()->sync($validated['product_ids']);
            }

            // Привязка пользователей
            if (!empty($validated['user_ids'])) {
                $discount->users()->sync($validated['user_ids']);
            }

            DB::commit();

            return redirect()
                ->route('admin.discounts.index')
                ->with('success', 'Скидка успешно создана');
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
        $discount->load(['products.brand', 'products.media', 'users']);

        return Inertia::render('Admin/Pages/Discounts/Show', [
            'discount' => [
                'id' => $discount->id,
                'name' => $discount->name,
                'percentage' => $discount->percentage,
                'external_id' => $discount->external_id,
                'is_posted' => $discount->is_posted,
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
            ],
        ]);
    }

    /**
     * Show the form for editing the specified discount.
     */
    public function edit(Discount $discount): Response
    {
        $discount->load(['products', 'users']);

        return Inertia::render('Admin/Pages/Discounts/Edit', [
            'discount' => [
                'id' => $discount->id,
                'name' => $discount->name,
                'percentage' => $discount->percentage,
                'external_id' => $discount->external_id,
                'is_posted' => $discount->is_posted,
                'product_ids' => $discount->products->pluck('id'),
                'user_ids' => $discount->users->pluck('id'),
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
            'percentage' => 'required|numeric|min:0|max:100',
            'external_id' => 'nullable|uuid|unique:discounts,external_id,' . $discount->id,
            'is_posted' => 'boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            $discount->update([
                'name' => $validated['name'] ?? null,
                'percentage' => $validated['percentage'],
                'external_id' => $validated['external_id'] ?? null,
                'is_posted' => $validated['is_posted'] ?? false,
            ]);

            // Синхронизация товаров
            $discount->products()->sync($validated['product_ids'] ?? []);

            // Синхронизация пользователей
            $discount->users()->sync($validated['user_ids'] ?? []);

            DB::commit();

            return redirect()
                ->route('admin.discounts.index')
                ->with('success', 'Скидка успешно обновлена');
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

            return redirect()
                ->route('admin.discounts.index')
                ->with('success', 'Скидка успешно удалена');
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
}
