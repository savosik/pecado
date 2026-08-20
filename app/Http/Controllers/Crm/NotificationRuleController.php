<?php

namespace App\Http\Controllers\Crm;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSignal;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\ConditionValidator;
use App\Services\Notifications\Pulse\NotificationEventRegistry;
use App\Services\Notifications\Pulse\NotificationPulse;
use App\Services\Notifications\Pulse\NotificationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Пульт уведомлений — правила маршрутизации писем.
 *
 * Раздел отвечает на два вопроса: «кто получит письмо, когда произойдёт X»
 * и «почему конкретное письмо ушло именно туда». До него ответ на оба давало
 * только чтение кода.
 *
 * Разграничение прав: менеджер ведёт правила своих партнёров, а правила
 * «для всех партнёров» и системные — только под crm-notifications-all.
 * Массовая рассылка по базе не должна быть в руках одного менеджера.
 */
class NotificationRuleController extends CrmController
{
    public function __construct(
        private readonly NotificationRuleService $rules,
        private readonly NotificationEventRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);

        $filters = $request->validate([
            'event_key' => ['nullable', 'string', 'max:64'],
            'scope' => ['nullable', Rule::in(['policy', 'exceptions', 'system'])],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $query = NotificationRule::query()
            ->visibleInCrm($actor)
            ->with(['recipients.contact', 'scopeUser:id,name,erp_name', 'scopeCompany:id,name', 'scopeManager:id,name']);

        if (filled($filters['event_key'] ?? null)) {
            $query->where('event_key', $filters['event_key']);
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        // Политика отдела и исключения разведены намеренно: основной способ
        // настройки — одно правило на всю базу с получателем-ролью, а поштучные
        // правила нужны только под просьбы конкретных клиентов.
        match ($filters['scope'] ?? null) {
            'policy' => $query->whereIn('scope_type', [NotificationRule::SCOPE_GLOBAL, NotificationRule::SCOPE_MANAGER])->where('is_system', false),
            'exceptions' => $query->whereIn('scope_type', [NotificationRule::SCOPE_USER, NotificationRule::SCOPE_COMPANY]),
            'system' => $query->where('is_system', true),
            default => null,
        };

        $rules = $query->orderBy('event_key')->orderBy('priority')->orderBy('id')->get();

        return Inertia::render('Crm/Pages/Notifications/Index', [
            'rules' => $rules->map(fn (NotificationRule $rule) => $this->payload($rule, $actor))->values(),
            'filters' => $filters,
            'events' => $this->registry->groupedForConstructor(),
            'canManageAll' => $actor->can('crm-notifications-all.edit'),
            'counts' => [
                'policy' => $rules->where('is_system', false)->whereIn('scope_type', [NotificationRule::SCOPE_GLOBAL, NotificationRule::SCOPE_MANAGER])->count(),
                'exceptions' => $rules->whereIn('scope_type', [NotificationRule::SCOPE_USER, NotificationRule::SCOPE_COMPANY])->count(),
                'system' => $rules->where('is_system', true)->count(),
            ],
        ]);
    }

    /**
     * Справочники конструктора: события, поля условий, виды получателей.
     */
    public function meta(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $eventKey = (string) $request->query('event_key', '');

        $fields = $eventKey !== ''
            ? collect($this->registry->fieldsFor($eventKey))->map(fn ($spec) => $spec->toArray())->values()
            : collect();

        return response()->json([
            'events' => $this->registry->groupedForConstructor(),
            'fields' => $fields,
            'roles' => ClientContactRole::options(),
            'recipient_kinds' => collect(NotificationRuleRecipient::kinds())
                ->reject(fn (string $kind) => $kind === NotificationRuleRecipient::KIND_CONTACT_ROLE && $eventKey === '')
                ->map(fn (string $kind) => [
                    'value' => $kind,
                    'label' => NotificationRuleRecipient::kindLabel($kind),
                ])->values(),
            'config_lists' => collect((array) config('notification_pulse.config_recipient_lists'))
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'can_manage_all' => $actor->can('crm-notifications-all.edit'),
        ]);
    }

    /**
     * Контакты партнёра для подстановки в получатели.
     */
    public function contacts(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $userId = $data['user_id'] ?? null;

        if ($userId === null && filled($data['company_id'] ?? null)) {
            $userId = Company::query()->visibleInCrm($actor)->whereKey($data['company_id'])->value('user_id');
        }

        if ($userId === null) {
            return response()->json(['data' => []]);
        }

        User::query()->visibleInCrm($actor)->findOrFail($userId);

        $contacts = ClientContact::query()
            ->where('user_id', $userId)
            ->deliverable()
            ->orderByDesc('is_primary')
            ->get();

        return response()->json([
            'data' => $contacts->map(fn (ClientContact $c) => [
                'id' => $c->id,
                'label' => $c->full_name.' — '.$c->role->label(),
                'email' => $c->email,
                'company_id' => $c->company_id,
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $data = $this->validated($request, $actor);

        $rule = $this->rules->create($data, $actor);

        return redirect()
            ->route('crm.notifications.index')
            ->with('success', 'Правило создано: '.$this->rules->humanize($rule));
    }

    public function update(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $model = NotificationRule::query()->visibleInCrm($actor)->findOrFail($rule);

        $this->assertEditable($model, $actor);

        $data = $this->validated($request, $actor, $model);
        $this->rules->update($model, $data, $actor);

        return back()->with('success', 'Правило сохранено');
    }

    public function toggle(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $model = NotificationRule::query()->visibleInCrm($actor)->findOrFail($rule);

        $this->assertEditable($model, $actor);

        $model->update(['is_active' => ! $model->is_active, 'updated_by_user_id' => $actor->id]);

        return back()->with('success', $model->is_active ? 'Правило включено' : 'Правило выключено');
    }

    public function destroy(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $model = NotificationRule::query()->visibleInCrm($actor)->findOrFail($rule);

        // Системное правило воспроизводит поведение кода: удалить его нельзя,
        // иначе исчезло бы объяснение, почему письмо уходит по умолчанию.
        if ($model->is_system) {
            abort(422, 'Системное правило нельзя удалить — его можно выключить или переопределить');
        }

        $this->assertEditable($model, $actor);
        $model->delete();

        return back()->with('success', 'Правило удалено');
    }

    /**
     * Копия системного правила, перекрывающая его поведение.
     */
    public function override(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->crmActor($request);

        abort_unless($actor->can('crm-notifications-all.edit'), 403);

        $system = NotificationRule::query()->visibleInCrm($actor)->where('is_system', true)->findOrFail($rule);
        $copy = $this->rules->override($system, $actor);

        return back()->with('success', 'Создана копия правила «'.$copy->name.'». Она выключена — проверьте и включите.');
    }

    /**
     * Предпросмотр «кто получит»: прогон движка на последних реальных событиях
     * этого типа без отправки писем.
     */
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'event_key' => ['required', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $signals = NotificationSignal::query()
            ->where('event_key', $data['event_key'])
            ->when(filled($data['company_id'] ?? null), fn ($q) => $q->where('company_id', $data['company_id']))
            ->when(filled($data['user_id'] ?? null), fn ($q) => $q->where('client_user_id', $data['user_id']))
            ->latest('id')
            ->limit(5)
            ->get();

        if ($signals->isEmpty()) {
            return response()->json([
                'data' => [],
                'note' => 'Реальных событий этого типа пока не было — предпросмотр покажет адресатов, когда они появятся.',
            ]);
        }

        $pulse = app(NotificationPulse::class);
        $rows = [];

        foreach ($signals as $signal) {
            $result = $pulse->preview(new PulseSignal(
                eventKey: $signal->event_key,
                clientUserId: $signal->client_user_id,
                companyId: $signal->company_id,
                data: (array) $signal->data,
                view: (array) $signal->view,
            ));

            $rows[] = [
                'occurred_at' => $signal->created_at?->format('d.m.Y H:i'),
                'matched' => $result['matched'],
                'recipients' => \App\Models\NotificationDelivery::query()
                    ->where('signal_uuid', $result['signal_uuid'])
                    ->get(['recipient', 'rule_name'])
                    ->map(fn ($d) => ['email' => $d->recipient, 'rule' => $d->rule_name])
                    ->values(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Тестовое письмо — только на адрес самого сотрудника.
     *
     * Отправить «тест» клиенту нельзя намеренно: проверка правила не должна
     * иметь возможность превратиться в реальное письмо не тому человеку.
     */
    public function testSend(Request $request, int $rule): RedirectResponse
    {
        $actor = $this->crmActor($request);
        $model = NotificationRule::query()->visibleInCrm($actor)->findOrFail($rule);

        if (blank($actor->email)) {
            return back()->with('error', 'У вашей учётной записи не указан адрес электронной почты');
        }

        $signal = NotificationSignal::query()
            ->where('event_key', $model->event_key)
            ->latest('id')
            ->first();

        \Illuminate\Support\Facades\Notification::route('mail', $actor->email)->notify(
            new \App\Notifications\Pulse\PulseNotification(
                signal: new PulseSignal(
                    eventKey: $model->event_key,
                    data: (array) ($signal?->data ?? []),
                    view: (array) ($signal?->view ?? [
                        'title' => 'Проверка правила «'.$model->name.'»',
                        'body' => 'Так будет выглядеть письмо по этому правилу.',
                    ]),
                ),
                delivery: new \App\Models\NotificationDelivery(['channel' => 'email']),
                subject: '[Проверка] '.$model->name,
                template: 'mail.pulse.default',
            )
        );

        return back()->with('success', 'Проверочное письмо отправлено на '.$actor->email);
    }

    /**
     * Каталог пресетов и предпросмотр применения к контрагенту.
     */
    public function presets(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $applier = app(\App\Services\Notifications\Pulse\PresetApplier::class);

        $data = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'preset' => ['nullable', 'string', 'max:64'],
        ]);

        $payload = ['catalog' => $applier->catalog()];

        if (filled($data['company_id'] ?? null) && filled($data['preset'] ?? null)) {
            $company = Company::query()->visibleInCrm($actor)->findOrFail($data['company_id']);
            $payload['preview'] = $applier->preview($data['preset'], $company);
        }

        return response()->json($payload);
    }

    /**
     * Применить пресет к контрагенту — та самая «одна кнопка».
     */
    public function applyPreset(Request $request): RedirectResponse
    {
        $actor = $this->crmActor($request);

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'preset' => ['required', 'string', 'max:64'],
        ], [], ['company_id' => 'контрагент', 'preset' => 'пресет']);

        $company = Company::query()->visibleInCrm($actor)->findOrFail($data['company_id']);

        $result = app(\App\Services\Notifications\Pulse\PresetApplier::class)
            ->apply($data['preset'], $company, $actor);

        $message = "Создано правил: {$result['created']}.";

        if ($result['skipped'] > 0) {
            $message .= " Пропущено: {$result['skipped']}.";
        }

        // Недостающие роли называются вслух: молчаливый пропуск оставил бы
        // менеджера в уверенности, что настроено, а письмо бы не ушло.
        if ($result['missing'] !== []) {
            $roles = collect($result['missing'])->pluck('role_label')->unique()->implode(', ');
            $message .= " У контрагента нет контактов с ролями: {$roles} — добавьте их на вкладке «Контакты».";
        }

        return back()->with('success', $message);
    }

    /**
     * Покрытие политики отдела: где адресной книги не хватает.
     */
    public function coverage(Request $request): Response
    {
        $actor = $this->crmActor($request);

        return Inertia::render('Crm/Pages/Notifications/Coverage', [
            'rows' => app(\App\Services\Notifications\Pulse\PresetApplier::class)->coverage($actor),
            'contactsTotal' => \App\Models\ClientContact::query()->visibleInCrm($actor)->deliverable()->count(),
            'companiesTotal' => Company::query()->visibleInCrm($actor)->count(),
        ]);
    }

    private function assertEditable(NotificationRule $rule, User $actor): void
    {
        abort_unless($rule->isEditableBy($actor), 403,
            'Правила «для всех партнёров» и системные правила ведёт руководитель отдела');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $actor, ?NotificationRule $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_key' => ['required', 'string', 'max:64'],
            'scope_type' => ['required', Rule::in([
                NotificationRule::SCOPE_GLOBAL,
                NotificationRule::SCOPE_USER,
                NotificationRule::SCOPE_COMPANY,
                NotificationRule::SCOPE_MANAGER,
            ])],
            'scope_user_id' => ['nullable', 'integer'],
            'scope_company_id' => ['nullable', 'integer'],
            'scope_manager_id' => ['nullable', 'integer'],
            'conditions' => ['nullable', 'array'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'stop_processing' => ['boolean'],
            'is_active' => ['boolean'],
            'subject_override' => ['nullable', 'string', 'max:512'],
            'throttle_seconds' => ['nullable', 'integer', 'min:0', 'max:2592000'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.kind' => ['required', Rule::in(NotificationRuleRecipient::kinds())],
            'recipients.*.contact_id' => ['nullable', 'integer'],
            'recipients.*.value' => ['nullable', 'string', 'max:255'],
            'recipients.*.copy_type' => ['nullable', Rule::in(['to', 'cc', 'bcc'])],
            'recipients.*.is_fallback' => ['boolean'],
        ], [], [
            'name' => 'название',
            'event_key' => 'событие',
            'scope_type' => 'область',
            'recipients' => 'получатели',
            'priority' => 'приоритет',
        ]);

        if (! $this->registry->isValidRuleKey($data['event_key'])) {
            abort(422, 'Неизвестное событие');
        }

        $errors = app(ConditionValidator::class)->validate($data['conditions'] ?? null, $data['event_key']);

        if ($errors !== []) {
            abort(422, implode('; ', $errors));
        }

        $this->assertScopeIsAllowed($data, $actor);
        $this->assertRecipientsAreSane($data, $actor);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertScopeIsAllowed(array $data, User $actor): void
    {
        $scope = $data['scope_type'];

        if (in_array($scope, [NotificationRule::SCOPE_GLOBAL, NotificationRule::SCOPE_MANAGER], true)) {
            abort_unless($actor->can('crm-notifications-all.edit'), 403,
                'Правила «для всех партнёров» ведёт руководитель отдела');

            return;
        }

        if ($scope === NotificationRule::SCOPE_USER) {
            abort_if(blank($data['scope_user_id'] ?? null), 422, 'Выберите партнёра');
            User::query()->visibleInCrm($actor)->findOrFail($data['scope_user_id']);
        }

        if ($scope === NotificationRule::SCOPE_COMPANY) {
            abort_if(blank($data['scope_company_id'] ?? null), 422, 'Выберите контрагента');
            Company::query()->visibleInCrm($actor)->findOrFail($data['scope_company_id']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertRecipientsAreSane(array $data, User $actor): void
    {
        $allowedConfigKeys = array_keys((array) config('notification_pulse.config_recipient_lists', []));

        foreach ($data['recipients'] as $recipient) {
            $kind = $recipient['kind'];

            if ($kind === NotificationRuleRecipient::KIND_CONTACT) {
                abort_if(blank($recipient['contact_id'] ?? null), 422, 'Выберите контакт');

                // Конкретный человек бессмыслен в правиле «для всех партнёров»:
                // событие другого клиента к нему отношения не имеет.
                abort_if(
                    $data['scope_type'] === NotificationRule::SCOPE_GLOBAL,
                    422,
                    'Конкретный контакт нельзя указать в правиле для всех партнёров — используйте роль',
                );

                ClientContact::query()->visibleInCrm($actor)->findOrFail($recipient['contact_id']);
            }

            if ($kind === NotificationRuleRecipient::KIND_CONTACT_ROLE) {
                abort_unless(
                    in_array((string) ($recipient['value'] ?? ''), ClientContactRole::values(), true),
                    422,
                    'Выберите роль контакта',
                );
            }

            if ($kind === NotificationRuleRecipient::KIND_EMAIL || $kind === NotificationRuleRecipient::KIND_SUPPRESS) {
                abort_if(blank($recipient['value'] ?? null), 422, 'Укажите адрес электронной почты');
            }

            if ($kind === NotificationRuleRecipient::KIND_CONFIG_LIST) {
                abort_unless(
                    in_array((string) ($recipient['value'] ?? ''), $allowedConfigKeys, true),
                    422,
                    'Недопустимый список адресов',
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(NotificationRule $rule, User $actor): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'event_key' => $rule->event_key,
            'event_label' => $this->registry->label($rule->event_key),
            'scope_type' => $rule->scope_type,
            'scope_label' => match ($rule->scope_type) {
                NotificationRule::SCOPE_USER => $rule->scopeUser?->display_name,
                NotificationRule::SCOPE_COMPANY => $rule->scopeCompany?->name,
                NotificationRule::SCOPE_MANAGER => $rule->scopeManager?->name,
                default => 'Все партнёры',
            },
            'scope_user_id' => $rule->scope_user_id,
            'scope_company_id' => $rule->scope_company_id,
            'conditions' => $rule->conditions,
            'priority' => $rule->priority,
            'stop_processing' => $rule->stop_processing,
            'is_active' => $rule->is_active,
            'is_system' => $rule->is_system,
            'is_policy' => $rule->isPolicy(),
            'throttle_seconds' => $rule->throttle_seconds,
            'matched_count' => $rule->matched_count,
            'last_matched_at' => $rule->last_matched_at?->format('d.m.Y H:i'),
            'is_stale' => $rule->matched_count === 0
                && $rule->created_at?->lt(now()->subDays(60)),
            'humanized' => $this->rules->humanize($rule),
            'can_edit' => $rule->isEditableBy($actor),
            'recipients' => $rule->recipients->map(fn (NotificationRuleRecipient $r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'kind_label' => NotificationRuleRecipient::kindLabel($r->kind),
                'contact_id' => $r->contact_id,
                'contact_name' => $r->contact?->full_name,
                'value' => $r->value,
                'copy_type' => $r->copy_type,
                'is_fallback' => $r->is_fallback,
            ])->values(),
        ];
    }
}
