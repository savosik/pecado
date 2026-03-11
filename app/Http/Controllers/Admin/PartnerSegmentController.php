<?php

namespace App\Http\Controllers\Admin;

use App\Models\PartnerSegment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class PartnerSegmentController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of partner segments.
     */
    public function index(Request $request): Response
    {
        $query = PartnerSegment::query()
            ->withCount('users');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'uuid', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $segments = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/PartnerSegments/Index', [
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
     * Show the form for creating a new partner segment.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/PartnerSegments/Create');
    }

    /**
     * Store a newly created partner segment in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'uuid'     => 'nullable|string|max:255|unique:partner_segments,uuid',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ], [
            'name.required'   => 'Название обязательно для заполнения.',
            'name.max'        => 'Название не должно превышать 255 символов.',
            'uuid.unique'     => 'Сегмент с таким UUID уже существует.',
            'user_ids.array'  => 'Партнёры должны быть массивом.',
            'user_ids.*.exists' => 'Один из выбранных партнёров не найден.',
        ]);

        DB::beginTransaction();
        try {
            $segment = PartnerSegment::create([
                'name' => $validated['name'],
                'uuid' => $validated['uuid'] ?? null,
            ]);

            if (!empty($validated['user_ids'])) {
                $segment->users()->sync($validated['user_ids']);
            }

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.partner-segments.index', 'admin.partner-segments.edit', $segment, 'Сегмент партнёров успешно создан');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании сегмента: ' . $e->getMessage()]);
        }
    }

    /**
     * Show the form for editing the specified partner segment.
     */
    public function edit(PartnerSegment $partnerSegment): Response
    {
        $partnerSegment->load('users');

        return Inertia::render('Admin/Pages/PartnerSegments/Edit', [
            'segment' => [
                'id'         => $partnerSegment->id,
                'name'       => $partnerSegment->name,
                'uuid'       => $partnerSegment->uuid,
                'created_at' => $partnerSegment->created_at?->format('d.m.Y H:i'),
                'updated_at' => $partnerSegment->updated_at?->format('d.m.Y H:i'),
                'users'      => $partnerSegment->users->map(fn ($u) => [
                    'id'    => $u->id,
                    'name'  => $u->full_name,
                    'email' => $u->email,
                    'label' => "{$u->full_name} ({$u->email})",
                ]),
            ],
        ]);
    }

    /**
     * Update the specified partner segment in storage.
     */
    public function update(Request $request, PartnerSegment $partnerSegment): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'uuid'     => 'nullable|string|max:255|unique:partner_segments,uuid,' . $partnerSegment->id,
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ], [
            'name.required'   => 'Название обязательно для заполнения.',
            'name.max'        => 'Название не должно превышать 255 символов.',
            'uuid.unique'     => 'Сегмент с таким UUID уже существует.',
            'user_ids.array'  => 'Партнёры должны быть массивом.',
            'user_ids.*.exists' => 'Один из выбранных партнёров не найден.',
        ]);

        DB::beginTransaction();
        try {
            $partnerSegment->update([
                'name' => $validated['name'],
                'uuid' => $validated['uuid'] ?? null,
            ]);

            // Синхронизация партнёров
            $partnerSegment->users()->sync($validated['user_ids'] ?? []);

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.partner-segments.index', 'admin.partner-segments.edit', $partnerSegment, 'Сегмент партнёров успешно обновлён');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении сегмента: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified partner segment from storage.
     */
    public function destroy(PartnerSegment $partnerSegment): RedirectResponse
    {
        try {
            $partnerSegment->delete();

            return redirect()->route('admin.partner-segments.index')->with('success', 'Сегмент партнёров успешно удалён');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'Ошибка при удалении сегмента: ' . $e->getMessage()]);
        }
    }

    /**
     * Search users (partners) for async selector.
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
                    'id'    => $user->id,
                    'name'  => $user->full_name,
                    'email' => $user->email,
                    'label' => "{$user->full_name} ({$user->email})",
                ];
            });

        return response()->json($users);
    }
}
