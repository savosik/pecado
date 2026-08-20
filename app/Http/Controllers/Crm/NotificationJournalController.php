<?php

namespace App\Http\Controllers\Crm;

use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\NotificationSignal;
use App\Models\User;
use App\Services\Notifications\Pulse\ConditionEvaluator;
use App\Services\Notifications\Pulse\NotificationEventRegistry;
use App\Services\Notifications\Pulse\NotificationRuleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Журнал уведомлений и трасса сигнала.
 *
 * Отвечает на вопрос «почему клиенту ничего не пришло» без обращения
 * к разработчику. Показывает и отрицательные исходы: адрес в стоп-листе,
 * сработал троттлинг, не совпало ни одно правило.
 */
class NotificationJournalController extends CrmController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly NotificationEventRegistry $registry,
        private readonly NotificationRuleService $rules,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);

        $filters = $request->validate([
            'event_key' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in([
                NotificationDelivery::STATUS_SENT,
                NotificationDelivery::STATUS_QUEUED,
                NotificationDelivery::STATUS_SKIPPED,
                NotificationDelivery::STATUS_FAILED,
            ])],
            'recipient' => ['nullable', 'string', 'max:191'],
            'client_user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = NotificationDelivery::query()
            ->with(['rule:id,name,is_system', 'client:id,name,erp_name'])
            ->when(filled($filters['event_key'] ?? null), fn ($q) => $q->where('event_key', $filters['event_key']))
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['recipient'] ?? null), fn ($q) => $q->where('recipient', 'like', '%'.$filters['recipient'].'%'))
            ->when(filled($filters['client_user_id'] ?? null), fn ($q) => $q->where('client_user_id', $filters['client_user_id']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'));

        $this->applyScope($query, $actor);

        $deliveries = $query->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Crm/Pages/Notifications/Journal', [
            'deliveries' => $deliveries->through(fn (NotificationDelivery $d) => [
                'id' => $d->id,
                'signal_uuid' => $d->signal_uuid,
                'created_at' => $d->created_at?->format('d.m.Y H:i'),
                'event_key' => $d->event_key,
                'event_label' => $this->registry->label($d->event_key),
                'client_name' => $d->client?->display_name,
                'client_user_id' => $d->client_user_id,
                'recipient' => $d->recipient,
                'rule_name' => $d->rule_name,
                'rule_id' => $d->notification_rule_id,
                'status' => $d->status,
                'status_label' => NotificationDelivery::statusLabel($d->status),
                'skip_reason_label' => $d->skip_reason ? NotificationDelivery::skipReasonLabel($d->skip_reason) : null,
                'subject' => $d->subject,
                'sent_at' => $d->sent_at?->format('d.m.Y H:i'),
            ]),
            'filters' => $filters,
            'events' => $this->registry->groupedForConstructor(),
        ]);
    }

    /**
     * Трасса: какие правила рассматривались, какие совпали и почему,
     * где сработала остановка, кто отсеян и по какой причине.
     */
    public function signal(Request $request, string $uuid): Response
    {
        $actor = $this->crmActor($request);

        $signal = NotificationSignal::query()->where('uuid', $uuid)->firstOrFail();

        // Сигнал чужого клиента невидим так же, как его карточка
        if ($signal->client_user_id !== null && ! $actor->can('crm-notifications-all.view')) {
            User::query()->visibleInCrm($actor)->findOrFail($signal->client_user_id);
        }

        return Inertia::render('Crm/Pages/Notifications/SignalTrace', [
            'signal' => [
                'uuid' => $signal->uuid,
                'event_key' => $signal->event_key,
                'event_label' => $this->registry->label($signal->event_key),
                'created_at' => $signal->created_at?->format('d.m.Y H:i:s'),
                'occurred_at' => $signal->occurred_at?->format('d.m.Y H:i:s'),
                'mode' => $signal->mode,
                'matched_rules_count' => $signal->matched_rules_count,
                'deliveries_count' => $signal->deliveries_count,
                'client_name' => $signal->client?->display_name,
                'client_user_id' => $signal->client_user_id,
                'company_name' => $signal->company?->name,
                'data' => $this->humanizeData($signal),
                'tags' => (array) $signal->tags,
            ],
            'rules' => $this->replayRules($signal),
            'deliveries' => NotificationDelivery::where('signal_uuid', $signal->uuid)
                ->get()
                ->map(fn (NotificationDelivery $d) => [
                    'recipient' => $d->recipient,
                    'rule_name' => $d->rule_name,
                    'status_label' => NotificationDelivery::statusLabel($d->status),
                    'skip_reason_label' => $d->skip_reason ? NotificationDelivery::skipReasonLabel($d->skip_reason) : null,
                ])->values(),
        ]);
    }

    /**
     * Повторный разбор правил для показа: какие рассматривались и что решили.
     *
     * Считается на лету, а не хранится: правила могли измениться, и честнее
     * показать текущий разбор, пометив это, чем выдавать старый снимок
     * за сегодняшнее поведение.
     *
     * @return array<int, array<string, mixed>>
     */
    private function replayRules(NotificationSignal $signal): array
    {
        $evaluator = app(ConditionEvaluator::class);
        $matchKeys = $this->registry->matchKeys($signal->event_key);

        $rules = NotificationRule::query()
            ->with('recipients.contact')
            ->whereIn('event_key', $matchKeys)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $result = [];
        $stopped = false;

        foreach ($rules as $rule) {
            if ($stopped) {
                $result[] = [
                    'name' => $rule->name,
                    'priority' => $rule->priority,
                    'state' => 'not_reached',
                    'note' => 'Не рассматривалось: разбор остановлен правилом выше',
                ];

                continue;
            }

            if (! $rule->is_active) {
                $result[] = [
                    'name' => $rule->name,
                    'priority' => $rule->priority,
                    'state' => 'inactive',
                    'note' => 'Правило выключено',
                ];

                continue;
            }

            $matched = $evaluator->matches($rule->conditions, (array) $signal->data, (array) $signal->tags);

            $result[] = [
                'name' => $rule->name,
                'priority' => $rule->priority,
                'state' => $matched ? 'matched' : 'skipped',
                'note' => $matched
                    ? $this->rules->humanize($rule)
                    : 'Условия не выполнены: '.$this->rules->humanize($rule),
                'stop_processing' => $rule->stop_processing,
            ];

            if ($matched && $rule->stop_processing) {
                $stopped = true;
            }
        }

        return $result;
    }

    /**
     * Контекст события человекочитаемо: технические ключи менеджеру не нужны.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function humanizeData(NotificationSignal $signal): array
    {
        $fields = $this->registry->fieldsFor($signal->event_key);
        $rows = [];

        foreach ((array) $signal->data as $key => $value) {
            $spec = $fields[$key] ?? null;

            if ($spec === null) {
                continue;
            }

            $readable = match (true) {
                is_bool($value) => $value ? 'да' : 'нет',
                is_array($value) => implode(', ', array_map('strval', $value)),
                default => (string) $value,
            };

            foreach ($spec->options as $option) {
                if ((string) $option['value'] === (string) $value) {
                    $readable = $option['label'];
                }
            }

            $rows[] = ['label' => $spec->label, 'value' => $readable === '' ? '—' : $readable];
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<NotificationDelivery>  $query
     */
    private function applyScope($query, User $actor): void
    {
        if ($actor->can('crm-notifications-all.view')) {
            return;
        }

        $clients = User::query()->inCrmScope($actor, \App\Enums\Crm\CrmScope::DEPARTMENT)->select('users.id');

        $query->where(function ($q) use ($clients) {
            $q->whereNull('client_user_id')->orWhereIn('client_user_id', $clients);
        });
    }
}
