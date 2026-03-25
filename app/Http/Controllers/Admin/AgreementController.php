<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agreement;
use App\Models\ProductSegment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AgreementController extends AdminController
{
    /**
     * Display a listing of agreements.
     */
    public function index(Request $request): Response
    {
        $query = Agreement::query()
            ->with(['user'])
            ->withCount('discounts');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        // Фильтрация по статусу
        if ($request->has('is_active') && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'is_active', 'starts_at', 'ends_at', 'created_at', 'uuid'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $agreements = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Agreements/Index', [
            'agreements' => $agreements,
            'filters' => [
                'search' => $search,
                'is_active' => $request->input('is_active'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new agreement.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Agreements/Create');
    }

    /**
     * Store a newly created agreement in storage.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'discounts' => 'array',
            'discounts.*.name' => 'required|string|max:255',
            'discounts.*.percentage' => 'required|numeric|min:0|max:100',
            'discounts.*.product_segment_id' => 'nullable|exists:product_segments,id',
        ]);

        $user = User::findOrFail($validated['user_id']);

        DB::transaction(function () use ($validated, $user) {
            $agreement = Agreement::create([
                'uuid' => (string) Str::uuid(),
                'name' => $validated['name'],
                'partner_uuid' => $user->erp_id ?? (string) Str::uuid(),
                'is_active' => $validated['is_active'] ?? false,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
            ]);

            if (!empty($validated['discounts'])) {
                foreach ($validated['discounts'] as $discountData) {
                    $segment = null;
                    if (!empty($discountData['product_segment_id'])) {
                        $segment = ProductSegment::find($discountData['product_segment_id']);
                    }

                    $agreement->discounts()->create([
                        'discount_uuid' => (string) Str::uuid(),
                        'name' => $discountData['name'],
                        'percentage' => (float) $discountData['percentage'],
                        'product_segment_uuid' => $segment ? $segment->uuid : null,
                    ]);
                }
            }
        });

        return redirect()->route('admin.agreements.index')->with('success', 'Соглашение успешно создано');
    }

    /**
     * Display the specified agreement.
     */
    public function show(Agreement $agreement): Response
    {
        $agreement->load(['user', 'discounts.productSegment']);

        return Inertia::render('Admin/Pages/Agreements/Show', [
            'agreement' => [
                'id' => $agreement->id,
                'uuid' => $agreement->uuid,
                'name' => $agreement->name,
                'is_active' => $agreement->is_active,
                'starts_at' => $agreement->starts_at?->format('d.m.Y H:i'),
                'ends_at' => $agreement->ends_at?->format('d.m.Y H:i'),
                'created_at' => $agreement->created_at?->format('d.m.Y H:i'),
                'user' => $agreement->user ? [
                    'id' => $agreement->user->id,
                    'name' => $agreement->user->full_name,
                    'email' => $agreement->user->email,
                ] : null,
                'discounts' => $agreement->discounts->map(function ($discount) {
                    return [
                        'id' => $discount->id,
                        'discount_uuid' => $discount->discount_uuid,
                        'name' => $discount->name,
                        'percentage' => $discount->percentage,
                        'product_segment' => $discount->productSegment ? [
                            'id' => $discount->productSegment->id,
                            'name' => $discount->productSegment->name,
                        ] : null,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified agreement.
     */
    public function edit(Agreement $agreement): Response
    {
        $agreement->load(['user', 'discounts']);

        return Inertia::render('Admin/Pages/Agreements/Edit', [
            'agreement' => [
                'id' => $agreement->id,
                'uuid' => $agreement->uuid,
                'name' => $agreement->name,
                'is_active' => $agreement->is_active,
                'starts_at' => $agreement->starts_at?->format('Y-m-d\TH:i'),
                'ends_at' => $agreement->ends_at?->format('Y-m-d\TH:i'),
                'user' => $agreement->user ? [
                    'id' => $agreement->user->id,
                    'name' => $agreement->user->full_name,
                ] : null,
                'discounts' => $agreement->discounts->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'percentage' => $d->percentage,
                ]),
            ],
        ]);
    }

    /**
     * Update the specified agreement in storage.
     */
    public function update(Request $request, Agreement $agreement): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);

        $agreement->update([
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? false,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
        ]);

        // Используем стандартный редирект, так как мы не импортировали трейт RedirectsAfterSave
        return redirect()->route('admin.agreements.index')->with('success', 'Соглашение успешно обновлено');
    }
}
