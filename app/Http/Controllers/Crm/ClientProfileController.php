<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Http\Requests\Crm\ChangeClientLifecycleRequest;
use App\Http\Requests\Crm\UpdateClientProfileRequest;
use App\Models\User;
use App\Services\Crm\ClientLifecycleService;
use App\Services\Crm\ClientProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Tags\Tag;

class ClientProfileController extends CrmController
{
    public function __construct(private readonly ClientProfileService $profiles) {}

    public function update(UpdateClientProfileRequest $request, int $client): RedirectResponse
    {
        // Тот же scope, что и в ClientController::show(): чужой клиент — 404,
        // иначе 403 подтвердил бы, что такой клиент существует.
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);

        $this->profiles->update($user, $request->validated(), $this->crmActor($request));

        return back()->with('success', 'Профиль клиента сохранён');
    }

    /**
     * Смена жизненного статуса. Лояльность (users.client_status_id) здесь не трогается:
     * ею владеет 1С и перезапишет её следующим сообщением partner.updated.
     */
    public function lifecycle(
        ChangeClientLifecycleRequest $request,
        int $client,
        ClientLifecycleService $lifecycle,
    ): RedirectResponse {
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);

        $status = ClientLifecycleStatus::from($request->validated('lifecycle_status'));

        $lifecycle->change($user, $status, $this->crmActor($request), $request->validated('reason'));

        return back()->with('success', "Жизненный статус клиента: {$status->label()}");
    }

    /**
     * Подсказки для поля «Интересы» — только теги своего типа.
     *
     * Товарные теги сюда не попадают: смешавшись однажды, справочник интересов
     * перестал бы быть пригодным для фильтров.
     */
    public function interests(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query'));

        // containing() ищет по JSON-пути name->{локаль}. Обычный LIKE по колонке
        // здесь бесполезен: Spatie хранит переводы с \uXXXX-экранированием,
        // и кириллица в сыром JSON не совпадает с введённым текстом.
        $tags = Tag::query()
            ->where('type', User::INTEREST_TAG_TYPE)
            ->when($query !== '', fn ($q) => $q->containing($query))
            ->orderBy('name')
            ->take(10)
            ->get()
            ->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => (string) $tag->name]);

        return response()->json($tags);
    }
}
