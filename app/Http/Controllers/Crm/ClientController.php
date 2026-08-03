<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmClientProfileRevision;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\ClientLifecycleService;
use App\Services\Crm\ClientProfileService;
use App\Services\Crm\CrmTaskService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends CrmController
{
    public function index(Request $request, CrmTaskService $tasks): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);

        $canSeeProfile = $actor->can('crm-profile.view');
        $canSeeTasks = $actor->can('crm-tasks.view');

        $query = User::query()
            ->visibleInCrm($actor)
            ->with(['personalManager:id,name', 'clientStatus:id,name,color'])
            ->when($canSeeProfile, fn ($q) => $q->with(
                'crmProfile:id,user_id,lifecycle_status,lifecycle_hint'
            ))
            ->when($canSeeTasks, fn ($q) => $q->withCount($tasks->activeTasksCount()));

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Фильтр по менеджеру — только тем, кто и так видит весь отдел.
        // Иначе менеджер подставил бы чужой manager_id в адрес и увидел чужих.
        $managerId = $seesAll ? $request->input('manager_id') : null;

        if ($managerId) {
            $query->where('personal_manager_id', $managerId);
        }

        $lifecycle = $canSeeProfile
            ? ClientLifecycleStatus::tryFrom((string) $request->input('lifecycle'))
            : null;

        if ($lifecycle !== null) {
            $this->filterByLifecycle($query, $lifecycle);
        }

        // Покрытие задачами: «по кому нет следующего шага» — рабочий список
        // на неделю, а не отчётная цифра.
        $coverage = $canSeeTasks && in_array($request->input('coverage'), ['uncovered', 'covered'], true)
            ? (string) $request->input('coverage')
            : null;

        if ($coverage !== null) {
            $active = TaskStatus::activeValues();
            $hasActiveTask = fn ($q) => $q->whereIn('status', $active);

            $coverage === 'uncovered'
                ? $query->whereDoesntHave('crmTasks', $hasActiveTask)
                : $query->whereHas('crmTasks', $hasActiveTask);
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'email', 'created_at'];
        if (in_array($sortBy, $allowedSortFields, true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        return Inertia::render('Crm/Pages/Clients/Index', [
            'clients' => $query->paginate($perPage)->withQueryString(),
            'managers' => $seesAll
                ? PersonalManager::query()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canSeeAll' => $seesAll,
            'canSeeTasks' => $canSeeTasks,
            'uncoveredCount' => $canSeeTasks ? $tasks->uncoveredClients($actor)->count() : null,
            'lifecycleOptions' => $canSeeProfile ? ClientLifecycleStatus::optionsWithColor() : [],
            'managerProfileLinked' => $seesAll || $actor->managerProfile !== null,
            'filters' => [
                'search' => $search,
                'manager_id' => $managerId,
                'lifecycle' => $lifecycle?->value,
                'coverage' => $coverage,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(
        Request $request,
        int $client,
        ClientProfileService $profiles,
        ClientLifecycleService $lifecycle,
    ): Response {
        // Резолвим через тот же scope: чужой клиент — 404, а не 403.
        $user = User::query()
            ->visibleInCrm($this->crmActor($request))
            ->with(['personalManager:id,name', 'clientStatus:id,name,color'])
            ->findOrFail($client);

        $canSeeProfile = $this->crmActor($request)->can('crm-profile.view');

        return Inertia::render('Crm/Pages/Clients/Show', [
            'profile' => $canSeeProfile ? $this->profilePayload($user, $profiles) : null,
            'profileOptions' => $canSeeProfile ? [
                'payment_behavior' => PaymentBehavior::options(),
                'preferred_channel' => PreferredChannel::options(),
                'sentiment' => ClientSentiment::options(),
                'lifecycle_status' => ClientLifecycleStatus::options(),
            ] : null,
            'lifecycle' => $canSeeProfile ? $this->lifecyclePayload($user, $profiles, $lifecycle) : null,
            'client' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
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
            ],
        ]);
    }

    /**
     * Фильтр по жизненному статусу.
     *
     * Клиент без профиля считается активным — так же, как задаёт дефолт колонки.
     * Без этой ветки фильтр «Активен» прятал бы всех, кого ещё никто не описывал,
     * то есть почти всю базу.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     */
    private function filterByLifecycle(\Illuminate\Database\Eloquent\Builder $query, ClientLifecycleStatus $status): void
    {
        $matchesProfile = fn ($profile) => $profile->where('lifecycle_status', $status->value);

        if ($status === ClientLifecycleStatus::ACTIVE) {
            $query->where(fn ($q) => $q
                ->whereDoesntHave('crmProfile')
                ->orWhereHas('crmProfile', $matchesProfile));

            return;
        }

        $query->whereHas('crmProfile', $matchesProfile);
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
     * Профиль + история заметок для карточки клиента.
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

        return [
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
