<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\UserKind;
use App\Http\Requests\Crm\AssignClientManagerRequest;
use App\Http\Requests\Crm\ChangeClientKindRequest;
use App\Http\Requests\Crm\ChangeClientLifecycleRequest;
use App\Http\Requests\Crm\UpdateClientProfileRequest;
use App\Models\PersonalManager;
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
        // Тот же scope, что и в ClientController::show(): чужой партнёр — 404,
        // иначе 403 подтвердил бы, что такой партнёр существует.
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);

        $this->profiles->update($user, $request->validated(), $this->crmActor($request));

        return back()->with('success', 'Профиль партнёра сохранён');
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

        return back()->with('success', "Жизненный статус партнёра: {$status->label()}");
    }

    /**
     * Страховой запас: показывать ли партнёру заниженные остатки по рисковым
     * товарам (buf-02). Только руками менеджера — подсказка по анкете в карточке
     * остаётся рекомендацией и ничего не проставляет сама.
     */
    public function stockBuffer(
        Request $request,
        int $client,
        ClientLifecycleService $lifecycle,
    ): RedirectResponse {
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $lifecycle->changeStockBuffer($user, $enabled, $this->crmActor($request));

        return back()->with('success', $enabled
            ? 'Страховой запас включён: партнёр видит заниженные остатки по рисковым товарам'
            : 'Страховой запас выключен: партнёр видит остатки как все');
    }

    /**
     * Предзаказы: предлагать ли партнёру заказ товара без остатка у поставщика.
     *
     * Выключают тем, кто оформляет предзаказы «на автомате», а потом просит
     * удалить: партнёр видит только наличие, корзина не переливает в предзаказ.
     * Партнёр может переключить то же самое сам в кабинете.
     */
    public function preorders(
        Request $request,
        int $client,
        ClientLifecycleService $lifecycle,
    ): RedirectResponse {
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $lifecycle->changePreorders($user, $enabled, $this->crmActor($request));

        return back()->with('success', $enabled
            ? 'Предзаказы включены: товар без остатка партнёр может заказать у поставщика'
            : 'Предзаказы выключены: партнёр видит и заказывает только то, что есть на складе');
    }

    /**
     * Закрепить партнёра за менеджером или снять закрепление.
     *
     * С v16.10.0 персонального менеджера 1С не присылает — распределяет
     * базу тот, кто отвечает за отдел (crm-clients-all.edit). Скоуп —
     * `visibleInCrm()` РОПа: партнёр без менеджера в него входит, так что
     * лид можно и закрепить, и оставить лидом.
     */
    public function manager(
        AssignClientManagerRequest $request,
        int $client,
        ClientLifecycleService $lifecycle,
    ): RedirectResponse {
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->findOrFail($client);

        $managerId = $request->validated('personal_manager_id');
        // Скрытая карточка (уволившийся, техническая) в выборе не участвует:
        // закрепить за ней нельзя, даже подставив id в запрос.
        $manager = $managerId === null
            ? null
            : PersonalManager::query()->active()->findOrFail($managerId);

        $lifecycle->changeManager($user, $manager, $this->crmActor($request), $request->validated('reason'));

        return back()->with('success', $manager === null
            ? "{$user->display_name}: менеджер не закреплён"
            : "{$user->display_name} закреплён за менеджером {$manager->name}");
    }

    /**
     * Тип аккаунта: убрать из базы партнёров отдела или вернуть обратно.
     *
     * Скоуп поиска — не `visibleInCrm()`, а вся база: помеченный сотрудником
     * аккаунт из CRM-выборки сразу выпадает, и по ней его было бы уже не найти,
     * чтобы отменить ошибочную пометку. Партнёры без менеджера сюда тоже
     * попадают: с v16.10.0 это лиды отдела, а не жильцы админки.
     */
    public function kind(
        ChangeClientKindRequest $request,
        int $client,
        ClientLifecycleService $lifecycle,
    ): RedirectResponse {
        $user = User::query()->findOrFail($client);

        $kind = UserKind::from($request->validated('user_kind'));

        $lifecycle->changeKind($user, $kind, $this->crmActor($request), $request->validated('reason'));

        if ($kind->belongsInCrm()) {
            return back()->with('success', "{$user->display_name} снова в базе партнёров отдела");
        }

        // Возвращаться некуда: карточка партнёра, с которой пришёл запрос, для
        // не-партнёра отдаёт 404.
        return redirect()
            ->route('crm.clients.index')
            ->with('success', "{$user->display_name}: тип аккаунта — {$kind->label()}. Аккаунт убран из базы партнёров отдела.");
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
