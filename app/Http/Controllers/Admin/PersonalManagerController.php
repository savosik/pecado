<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserKind;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PersonalManagerController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Пользователи, которых можно привязать к карточке менеджера:
     * ещё не занятые другой карточкой, плюс уже привязанный к текущей.
     *
     * @return Collection<int, User>
     */
    private function accountCandidates(?PersonalManager $personalManager = null): Collection
    {
        return User::query()
            ->where(function ($query) use ($personalManager) {
                $query->whereDoesntHave('managerProfile');

                if ($personalManager?->user_id) {
                    $query->orWhere('id', $personalManager->user_id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Учётка, привязанная к карточке менеджера, — сотрудник, а не клиент.
     *
     * 1С заводит менеджеров партнёрами наравне с покупателями и проставляет им
     * personal_manager_id, поэтому без этой пометки менеджер всплывает в CRM
     * среди собственных клиентов. Служебный тип не трогаем: если аккаунт уже
     * помечен как служебный, это осознанное решение администратора.
     */
    private function markLinkedAccountAsStaff(PersonalManager $manager): void
    {
        if ($manager->user_id === null) {
            return;
        }

        User::query()
            ->whereKey($manager->user_id)
            ->where('user_kind', UserKind::CLIENT->value)
            ->update(['user_kind' => UserKind::STAFF->value]);
    }

    /**
     * Правила и русские сообщения валидации, общие для store() и update().
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validationRules(?PersonalManager $personalManager = null): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'erp_uuid' => 'nullable|uuid|unique:personal_managers,erp_uuid'.($personalManager ? ','.$personalManager->id : ''),
            // Проверяем занятость до БД: иначе unique-индекс даст 500 вместо русской ошибки формы.
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('personal_managers', 'user_id')->ignore($personalManager?->id),
            ],
            'photo' => 'nullable|image|max:20480',
        ];

        $messages = [
            'name.required' => 'Имя обязательно для заполнения.',
            'name.max' => 'Имя не должно превышать 255 символов.',
            'phone.max' => 'Телефон не должен превышать 50 символов.',
            'email.email' => 'Введите корректный email.',
            'email.max' => 'Email не должен превышать 255 символов.',
            'erp_uuid.uuid' => 'UUID должен быть в формате xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.',
            'erp_uuid.unique' => 'Этот UUID уже используется другим менеджером.',
            'user_id.exists' => 'Выбранный пользователь не найден.',
            'user_id.unique' => 'Этот пользователь уже привязан к другой карточке менеджера.',
            'photo.image' => 'Файл должен быть изображением.',
            'photo.max' => 'Максимальный размер фото — 20 МБ.',
        ];

        return [$rules, $messages];
    }

    public function index(Request $request): Response
    {
        $query = PersonalManager::query()
            ->with('media')
            ->withCount('users');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'created_at'];
        if (in_array($sortBy, $allowedSortFields, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $personalManagers = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/PersonalManagers/Index', [
            'personalManagers' => $personalManagers,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Pages/PersonalManagers/Create', [
            'users' => $this->accountCandidates(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$rules, $messages] = $this->validationRules();

        $validated = $request->validate($rules, $messages);

        unset($validated['photo']);

        $manager = PersonalManager::create($validated);
        $this->markLinkedAccountAsStaff($manager);

        if ($request->hasFile('photo')) {
            $manager->addMediaFromRequest('photo')
                ->toMediaCollection('photo');
        }

        return $this->redirectAfterSave(
            $request,
            'admin.personal-managers.index',
            'admin.personal-managers.edit',
            $manager,
            'Персональный менеджер успешно создан'
        );
    }

    public function show(PersonalManager $personalManager): Response
    {
        $personalManager->load(['media', 'user']);
        $personalManager->loadCount('users');

        return Inertia::render('Admin/Pages/PersonalManagers/Show', [
            'personalManager' => [
                'id' => $personalManager->id,
                'erp_uuid' => $personalManager->erp_uuid,
                'name' => $personalManager->name,
                'phone' => $personalManager->phone,
                'email' => $personalManager->email,
                'photo_url' => $personalManager->getFirstMediaUrl('photo'),
                'users_count' => $personalManager->users_count,
                'user' => $personalManager->user ? [
                    'id' => $personalManager->user->id,
                    'name' => $personalManager->user->name,
                    'email' => $personalManager->user->email,
                ] : null,
                'created_at' => $personalManager->created_at?->format('d.m.Y H:i'),
                'updated_at' => $personalManager->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    public function edit(PersonalManager $personalManager): Response
    {
        $personalManager->load('media');

        return Inertia::render('Admin/Pages/PersonalManagers/Edit', [
            'personalManager' => [
                'id' => $personalManager->id,
                'erp_uuid' => $personalManager->erp_uuid,
                'user_id' => $personalManager->user_id,
                'name' => $personalManager->name,
                'phone' => $personalManager->phone,
                'email' => $personalManager->email,
                'photo_url' => $personalManager->getFirstMediaUrl('photo'),
                'photo_id' => $personalManager->getFirstMedia('photo')?->id,
            ],
            'users' => $this->accountCandidates($personalManager),
        ]);
    }

    public function update(Request $request, PersonalManager $personalManager): RedirectResponse
    {
        [$rules, $messages] = $this->validationRules($personalManager);

        $validated = $request->validate($rules, $messages);

        unset($validated['photo']);

        $personalManager->update($validated);
        $this->markLinkedAccountAsStaff($personalManager);

        if ($request->hasFile('photo')) {
            $personalManager->clearMediaCollection('photo');
            $personalManager->addMediaFromRequest('photo')
                ->toMediaCollection('photo');
        }

        return $this->redirectAfterSave(
            $request,
            'admin.personal-managers.index',
            'admin.personal-managers.edit',
            $personalManager,
            'Персональный менеджер успешно обновлён'
        );
    }

    public function destroy(PersonalManager $personalManager): RedirectResponse
    {
        $personalManager->delete();

        return redirect()
            ->route('admin.personal-managers.index')
            ->with('success', 'Персональный менеджер успешно удалён');
    }

    public function deleteMedia(PersonalManager $personalManager, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $personalManager->media()->findOrFail($validated['media_id']);
        $media->delete();

        return response()->json(['success' => true]);
    }
}
