<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\ClientStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientStatusController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of client statuses.
     */
    public function index(Request $request): Response
    {
        $query = ClientStatus::query()->with('media');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'amount_from', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $clientStatuses = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ClientStatuses/Index', [
            'clientStatuses' => $clientStatuses,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new client status.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/ClientStatuses/Create');
    }

    /**
     * Store a newly created client status in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => 'nullable|string',
            'amount_from' => 'nullable|numeric|min:0',
            'external_id' => 'nullable|string|max:255|unique:client_statuses,external_id',
            'image' => 'nullable|image|max:20480',
        ], [
            'name.required' => 'Название обязательно для заполнения.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'color.regex' => 'Цвет должен быть в формате #RRGGBB.',
            'amount_from.numeric' => 'Сумма должна быть числом.',
            'amount_from.min' => 'Сумма не может быть отрицательной.',
            'external_id.unique' => 'Такой внешний ИД уже существует.',
            'image.image' => 'Файл должен быть изображением.',
            'image.max' => 'Максимальный размер изображения — 20 МБ.',
        ]);

        unset($validated['image']);

        $clientStatus = ClientStatus::create($validated);

        // Загрузка изображения
        if ($request->hasFile('image')) {
            $clientStatus->addMediaFromRequest('image')
                ->toMediaCollection('image');
        }

        return $this->redirectAfterSave($request, 'admin.client-statuses.index', 'admin.client-statuses.edit', $clientStatus, 'Статус клиента успешно создан');
    }

    /**
     * Display the specified client status.
     */
    public function show(ClientStatus $clientStatus): Response
    {
        $clientStatus->load('media');

        return Inertia::render('Admin/Pages/ClientStatuses/Show', [
            'clientStatus' => [
                'id' => $clientStatus->id,
                'name' => $clientStatus->name,
                'color' => $clientStatus->color,
                'description' => $clientStatus->description,
                'amount_from' => $clientStatus->amount_from,
                'external_id' => $clientStatus->external_id,
                'image_url' => $clientStatus->getFirstMediaUrl('image'),
                'created_at' => $clientStatus->created_at?->format('d.m.Y H:i'),
                'updated_at' => $clientStatus->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified client status.
     */
    public function edit(ClientStatus $clientStatus): Response
    {
        $clientStatus->load('media');

        return Inertia::render('Admin/Pages/ClientStatuses/Edit', [
            'clientStatus' => [
                'id' => $clientStatus->id,
                'name' => $clientStatus->name,
                'color' => $clientStatus->color,
                'description' => $clientStatus->description,
                'amount_from' => $clientStatus->amount_from,
                'external_id' => $clientStatus->external_id,
                'image_url' => $clientStatus->getFirstMediaUrl('image'),
                'image_id' => $clientStatus->getFirstMedia('image')?->id,
            ],
        ]);
    }

    /**
     * Update the specified client status in storage.
     */
    public function update(Request $request, ClientStatus $clientStatus): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => 'nullable|string',
            'amount_from' => 'nullable|numeric|min:0',
            'external_id' => 'nullable|string|max:255|unique:client_statuses,external_id,'.$clientStatus->id,
            'image' => 'nullable|image|max:20480',
        ], [
            'name.required' => 'Название обязательно для заполнения.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'color.regex' => 'Цвет должен быть в формате #RRGGBB.',
            'amount_from.numeric' => 'Сумма должна быть числом.',
            'amount_from.min' => 'Сумма не может быть отрицательной.',
            'external_id.unique' => 'Такой внешний ИД уже существует.',
            'image.image' => 'Файл должен быть изображением.',
            'image.max' => 'Максимальный размер изображения — 20 МБ.',
        ]);

        unset($validated['image']);

        $clientStatus->update($validated);

        // Обновление изображения
        if ($request->hasFile('image')) {
            $clientStatus->clearMediaCollection('image');
            $clientStatus->addMediaFromRequest('image')
                ->toMediaCollection('image');
        }

        return $this->redirectAfterSave($request, 'admin.client-statuses.index', 'admin.client-statuses.edit', $clientStatus, 'Статус клиента успешно обновлён');
    }

    /**
     * Remove the specified client status from storage.
     */
    public function destroy(ClientStatus $clientStatus): RedirectResponse
    {
        $clientStatus->delete();

        return redirect()->route('admin.client-statuses.index')->with('success', 'Статус клиента успешно удалён');
    }

    /**
     * Delete a specific media file from the client status.
     */
    public function deleteMedia(ClientStatus $clientStatus, Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $clientStatus->media()->findOrFail($validated['media_id']);
        $media->delete();

        return response()->json(['success' => true]);
    }
}
