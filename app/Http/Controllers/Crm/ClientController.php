<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\BusinessType;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use App\Models\CrmClientFilterPreset;
use App\Models\CrmClientProfile;
use App\Models\CrmClientProfileRevision;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\ClientInsightService;
use App\Services\Crm\ClientLifecycleService;
use App\Services\Crm\ClientListService;
use App\Services\Crm\ClientProfileService;
use App\Services\Crm\ContractListService;
use App\Services\Crm\ContractorListService;
use App\Services\Crm\CrmTaskService;
use App\Support\Crm\ClientListFilters;
use App\Support\Crm\ClientPassport;
use App\Support\Crm\LastVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends CrmController
{
    public function index(Request $request, CrmTaskService $tasks, ClientListService $clients): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesDepartment($request);

        $canSeeProfile = $actor->can('crm-profile.view');
        $canSeeTasks = $actor->can('crm-tasks.view');
        $canSeePlans = $actor->can('crm-plans.view');

        $filters = ClientListFilters::fromRequest($request, $actor, $seesAll);

        return Inertia::render('Crm/Pages/Clients/Index', [
            'clients' => $clients->paginate($actor, $filters),
            'managers' => $seesAll
                ? PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canSeeAll' => $seesAll,
            'canSeeTasks' => $canSeeTasks,
            'canSeePlans' => $canSeePlans,
            'uncoveredCount' => $canSeeTasks ? $tasks->uncoveredClients($actor)->count() : null,
            'lifecycleOptions' => $canSeeProfile ? ClientLifecycleStatus::optionsWithColor() : [],
            'managerProfileLinked' => $seesAll || $actor->managerProfile !== null,
            'presets' => $this->presetsPayload($actor),
            'filters' => $filters->toArray(),
        ]);
    }

    /**
     * Сохранить текущий отбор списка.
     *
     * Отбор личный, поэтому отдельного права нет: он живёт под тем же
     * `crm-clients.view`, что и сам список — ровно как пресеты отчёта продаж.
     */
    public function storePreset(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'payload' => ['required', 'array'],
        ]);

        // Сохраняем не то, что прислал фронт, а разобранный набор: иначе в отборе
        // осел бы мусор из адресной строки и однажды вернулся бы в запрос.
        $filters = ClientListFilters::fromRequest(
            new Request($data['payload']),
            $actor,
            $this->seesDepartment($request),
        );

        $preset = $actor->crmClientFilterPresets()->create([
            'name' => $data['name'],
            'payload' => $filters->toArray(),
        ]);

        return response()->json([
            'id' => (int) $preset->getKey(),
            'name' => $preset->name,
            'payload' => $preset->payload,
        ], 201);
    }

    /**
     * Удалить свой отбор. Чужой — 404: его существование не подтверждаем.
     */
    public function destroyPreset(Request $request, int $preset): JsonResponse
    {
        $this->crmActor($request)
            ->crmClientFilterPresets()
            ->findOrFail($preset)
            ->delete();

        return response()->json(status: 204);
    }

    /**
     * Личные отборы актора.
     *
     * @return list<array{id: int, name: string, payload: array<string, mixed>}>
     */
    private function presetsPayload(User $actor): array
    {
        return $actor->crmClientFilterPresets()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CrmClientFilterPreset $preset): array => [
                'id' => (int) $preset->getKey(),
                'name' => $preset->name,
                'payload' => $preset->payload,
            ])
            ->all();
    }

    public function show(
        Request $request,
        int $client,
        ClientProfileService $profiles,
        ClientLifecycleService $lifecycle,
        ContractorListService $contractors,
        ContractListService $contractList,
    ): Response {
        // Резолвим через тот же scope: чужой партнёр — 404, а не 403.
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->with(['personalManager:id,name', 'clientStatus:id,name,color'])
            ->findOrFail($client);

        $canSeeProfile = $this->crmActor($request)->can('crm-profile.view');
        // Юрлица партнёра — отдельное право: у раздела «Контрагенты» оно своё,
        // и вкладка в карточке не должна открывать то, что раздел закрывает.
        $canSeeContractors = $this->crmActor($request)->can('crm-contractors.view');
        $canSeeContracts = $this->crmActor($request)->can('crm-contracts.view');
        // Лестница долга — те же деньги, что раздел «Финансы», право то же.
        $canSeeDebt = $this->crmActor($request)->can('crm-finance.view') && config('debt.enabled');

        return Inertia::render('Crm/Pages/Clients/Show', [
            'debt' => $canSeeDebt ? app(\App\Services\Debt\DebtStateService::class)->explain($user) : null,
            'canSeeDebt' => $canSeeDebt,
            'pauseMaxDays' => $this->crmActor($request)->can('crm-clients-all.view')
                ? (int) config('debt.pause_max_days_head', 30)
                : (int) config('debt.pause_max_days_manager', 14),
            'contractors' => $canSeeContractors ? $contractors->forPartner($user) : [],
            'canSeeContractors' => $canSeeContractors,
            'contracts' => $canSeeContracts ? $contractList->forPartner($user) : [],
            'canSeeContracts' => $canSeeContracts,
            'profile' => $canSeeProfile ? $this->profilePayload($user, $profiles) : null,
            'profileOptions' => $canSeeProfile ? ClientPassport::options() + [
                'payment_behavior' => PaymentBehavior::options(),
                'preferred_channel' => PreferredChannel::options(),
                'sentiment' => ClientSentiment::options(),
                'lifecycle_status' => ClientLifecycleStatus::options(),
            ] : null,
            // Секции паспорта приходят с бэкенда, а не описаны в JSX: подпись поля
            // и его правило проверки должны меняться одной правкой, иначе форма
            // однажды покажет то, чего сервер уже не принимает.
            'passportSections' => $canSeeProfile ? ClientPassport::sections() : null,
            'lifecycle' => $canSeeProfile ? $this->lifecyclePayload($user, $profiles, $lifecycle) : null,
            'client' => [
                'id' => $user->id,
                // Заголовок карточки — рабочее наименование из 1С; имя из кабинета
                // показываем рядом, только если партнёр назвал себя иначе.
                'name' => $user->display_name,
                'personal_name' => $user->personal_name_if_differs,
                'email' => $user->email,
                'phone' => $user->phone,
                // Номер без форматирования — для tel:-ссылки: из 1С он приходит
                // и в скобках, и с дефисами.
                'phone_digits' => $user->phone === null
                    ? null
                    : (preg_replace('/\D+/', '', $user->phone) ?: null),
                'city' => $user->city,
                'country' => $user->country,
                'status' => $user->status->value,
                'status_label' => $user->status_label,
                'manager' => $user->personalManager ? [
                    'id' => $user->personalManager->id,
                    'name' => $user->personalManager->name,
                ] : null,
                'client_status' => $user->clientStatus ? [
                    'name' => $user->clientStatus->name,
                    'color' => $user->clientStatus->color,
                ] : null,
                'created_at' => $user->created_at?->format('d.m.Y H:i'),
                // Пользуется ли партнёр кабинетом: заказы могут идти через 1С,
                // а на сайт он при этом не заходил ни разу.
                'last_visit' => LastVisit::payload($user->last_seen_at),
                // Страховой запас (buf-02). Рекомендация — подсказка по анкете,
                // не автопроставление: решение принимает менеджер.
                'stock_buffer' => [
                    'enabled' => (bool) $user->stock_buffer_enabled,
                    'recommended' => $canSeeProfile && $this->stockBufferRecommended($profiles->forClient($user)),
                ],
                // Предзаказы: выключают тем, кто оформляет их «на автомате», а потом
                // просит удалить. Срок поставки — для текста подсказки менеджеру.
                'preorders' => [
                    'enabled' => $user->preordersEnabled(),
                    'lead_label' => \App\Support\Preorder\PreorderTerms::leadLabel(),
                ],
            ],
            // Наши юрлица — для колонки и фильтра во вкладках «Заказы» и «Реализации».
            // Заглушки в список фильтра не идут: выбирать «юрлицо-UUID» менеджеру
            // незачем, а в самих документах оно видно с бейджем.
            'organizations' => config('erp.organizations.enabled')
                ? Organization::query()->ordered()->where('is_stub', false)->get(['id', 'name'])
                : [],
            'organizationsEnabled' => (bool) config('erp.organizations.enabled'),
        ]);
    }

    /**
     * Закупки партнёра за 12 месяцев: метрики, тренд, бренды и категории.
     *
     * Своей арифметики здесь нет — тот же {@see ClientInsightService}, что
     * питает провал в партнёра на «грядках». Средний чек в карточке и средний
     * чек на плитке обязаны совпадать, а два движка выручки этого не гарантируют.
     */
    public function insights(Request $request, int $client, ClientInsightService $insights): JsonResponse
    {
        $actor = $this->crmActor($request);

        // Тот же scope, что и в show(): чужой партнёр — 404, а не 403.
        $user = User::query()
            ->visibleInCrm($actor)
            ->findOrFail($client);

        return response()->json($insights->forClient($user, $actor, 12));
    }

    /**
     * Жизненный статус, подсказка системы и журнал смен.
     *
     * Статус лояльности сюда не входит: он уезжает во фронт в блоке `client`
     * как есть, с подписью «из 1С», и редактированию не подлежит.
     *
     * @return array<string, mixed>
     */
    private function lifecyclePayload(
        User $user,
        ClientProfileService $profiles,
        ClientLifecycleService $lifecycle,
    ): array {
        $profile = $profiles->forClient($user);
        $profile->loadMissing('lifecycleEditor:id,name');

        return [
            'status' => $profile->lifecycle_status->value,
            'status_label' => $profile->lifecycle_status->label(),
            'status_color' => $profile->lifecycle_status->color(),
            'changed_at' => $profile->lifecycle_changed_at?->format('d.m.Y H:i'),
            'changed_by' => $profile->lifecycleEditor?->name,
            'hint' => $profile->lifecycle_hint === null ? null : [
                'status' => $profile->lifecycle_hint->value,
                'label' => $profile->lifecycle_hint->label(),
                'reason' => $profile->lifecycle_hint_reason,
                'at' => $profile->lifecycle_hint_at?->format('d.m.Y'),
            ],
            'history' => $lifecycle->history($user),
        ];
    }

    /**
     * Похож ли партнёр по анкете на интернет-магазин или маркетплейсера.
     *
     * Только бейдж-рекомендация у галочки страхового запаса: анкета может быть
     * неточной, поэтому сама она ничего не включает (решение заказчика, buf-02).
     */
    private function stockBufferRecommended(CrmClientProfile $profile): bool
    {
        return in_array($profile->business_type, [BusinessType::ONLINE, BusinessType::SELLER], true)
            || (bool) $profile->works_with_marketplaces;
    }

    /**
     * Профиль + история заметок для карточки партнёра.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, ClientProfileService $profiles): array
    {
        $profile = $profiles->forClient($user);
        $profile->loadMissing('notesEditor:id,name');

        // История — последние 20 правок: дальше по времени она нужна редко,
        // а тянуть в карточку весь журнал незачем.
        $revisions = $profile->exists
            ? $profile->revisions()
                ->with('author:id,name')
                ->latest('id')
                ->take(20)
                ->get()
                ->map(fn (CrmClientProfileRevision $revision): array => [
                    'id' => $revision->id,
                    // user_id обнуляется при удалении сотрудника — ревизия переживает автора,
                    // хотя по связи Larastan этого не видит.
                    // @phpstan-ignore-next-line nullsafe.neverNull
                    'author' => $revision->author?->name ?? 'Сотрудник удалён',
                    'created_at' => $revision->created_at?->format('d.m.Y H:i'),
                    'notes_md' => $revision->notes_md,
                ])
                ->all()
            : [];

        return ClientPassport::values($profile) + [
            'passport_labels' => ClientPassport::labels($profile),
            'passport_completeness' => ClientPassport::completeness($profile),
            'decision_maker_name' => $profile->decision_maker_name,
            'decision_maker_role' => $profile->decision_maker_role,
            'decision_maker_contact' => $profile->decision_maker_contact,
            'decision_process' => $profile->decision_process,
            'payment_behavior' => $profile->payment_behavior?->value,
            'payment_behavior_label' => $profile->payment_behavior?->label(),
            'payment_terms' => $profile->payment_terms,
            'order_cycle_days' => $profile->order_cycle_days,
            'preferred_channel' => $profile->preferred_channel?->value,
            'sentiment' => $profile->sentiment?->value,
            'sentiment_label' => $profile->sentiment?->label(),
            'notes_md' => $profile->notes_md,
            'notes_updated_at' => $profile->notes_updated_at?->format('d.m.Y H:i'),
            'notes_updated_by' => $profile->notesEditor?->name,
            'interests' => $profiles->interests($user),
            'revisions' => $revisions,
        ];
    }
}
