<?php

namespace App\Services\Crm;

use App\Models\CrmCall;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Журнал звонков: скоуп видимости, запись разговора и форма ответа.
 *
 * Единая точка на все пути — диалог из таблицы, поле ввода ленты и будущий вебхук
 * АТС. Иначе резолв партнёра, создание следующего шага и проверка доступа
 * повторялись бы в каждом входе и однажды разошлись бы.
 */
class CrmCallService
{
    public function __construct(private readonly CrmTaskService $tasks) {}

    /**
     * Звонки, доступные актору.
     *
     * В отличие от задач — по партнёрам, а не по участию: разговор с партнёром
     * видит всякий, кто видит партнёра.
     *
     * @return Builder<CrmCall>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = CrmCall::query();

        if ($actor->can('crm-clients-all.view')) {
            return $query;
        }

        $managerId = $actor->managerProfile?->id;

        // Менеджер без карточки партнёров не ведёт — но свои записи (звонок
        // без привязки) остаются его.
        return $query->where(fn (Builder $inner) => $inner
            ->where('user_id', $actor->getKey())
            ->orWhereHas('client', fn (Builder $client) => $managerId === null
                ? $client->whereRaw('1 = 0')
                : $client->where('personal_manager_id', $managerId)));
    }

    /**
     * Записать звонок и, если нужно, следующий шаг.
     *
     * Обе записи одной транзакцией: звонок, после которого «договорились
     * перезвонить в среду», но задача не создалась, — это ровно та потеря,
     * из-за которой работа с партнёром и рассыпается.
     *
     * @param  array<string, mixed>  $data
     * @return array{call: CrmCall, follow_up: CrmTask|null}
     */
    public function log(User $actor, array $data, ?Model $related): array
    {
        return DB::transaction(function () use ($actor, $data, $related): array {
            $call = new CrmCall([
                'direction' => $data['direction'] ?? 'outgoing',
                'result' => $data['result'] ?? 'talked',
                'phone' => $data['phone'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'summary' => $data['summary'] ?? null,
                'started_at' => isset($data['started_at']) && $data['started_at'] !== ''
                    ? Carbon::parse((string) $data['started_at'])
                    : null,
                'duration_sec' => $data['duration_sec'] ?? null,
                'provider' => CrmCall::PROVIDER_MANUAL,
            ]);

            if ($related !== null) {
                $call->related()->associate($related);
            }

            $call->user_id = (int) $actor->getKey();
            // client_user_id и started_at проставляет сама модель.
            $call->save();

            $followUp = null;
            $followUpData = $data['follow_up'] ?? null;

            if (is_array($followUpData) && ($followUpData['title'] ?? '') !== '') {
                $followUp = $this->tasks->create(
                    $actor,
                    [
                        'title' => $followUpData['title'],
                        'description' => $followUpData['description'] ?? null,
                        // По умолчанию следующий шаг остаётся за тем, кто звонил.
                        'assignee_id' => $followUpData['assignee_id'] ?? $actor->getKey(),
                        'priority' => $followUpData['priority'] ?? 'normal',
                        'due_at' => $followUpData['due_at'] ?? null,
                    ],
                    // Привязку наследуем: следующий шаг по партнёру — шаг по тому же
                    // партнёру, искать его заново незачем.
                    $related,
                );

                $call->follow_up_task_id = (int) $followUp->getKey();
                $call->save();
            }

            return ['call' => $call, 'follow_up' => $followUp];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CrmCall $call, array $data): CrmCall
    {
        foreach (['direction', 'result', 'phone', 'contact_name', 'summary', 'duration_sec'] as $field) {
            if (array_key_exists($field, $data)) {
                $call->{$field} = $data[$field];
            }
        }

        if (array_key_exists('started_at', $data)) {
            $call->started_at = $data['started_at'] === null || $data['started_at'] === ''
                ? null
                : Carbon::parse((string) $data['started_at']);
        }

        $call->save();

        return $call;
    }

    /**
     * Форма звонка для фронта — одна на журнал, ленту и врезку.
     *
     * @return array<string, mixed>
     */
    public function payload(CrmCall $call, User $viewer): array
    {
        $related = $call->related;

        return [
            'id' => (int) $call->getKey(),
            'direction' => $call->direction->value,
            'direction_label' => $call->direction->label(),
            'direction_color' => $call->direction->color(),
            'result' => $call->result->value,
            'result_label' => $call->result->label(),
            'result_color' => $call->result->color(),
            'phone' => $call->phone,
            'contact_name' => $call->contact_name,
            'summary' => $call->summary,
            'started_at' => $call->started_at?->format('Y-m-d\TH:i'),
            'started_at_label' => $call->started_at?->format('d.m.Y H:i'),
            'created_at_label' => $call->created_at?->format('d.m.Y H:i'),
            'duration_sec' => $call->duration_sec,
            'duration_label' => $call->durationLabel(),
            'provider' => $call->provider,
            'recording_url' => $call->recording_url,
            'author' => [
                'id' => (int) $call->user_id,
                'name' => $call->author->name,
            ],
            'client_id' => $call->client_user_id === null ? null : (int) $call->client_user_id,
            'follow_up_task_id' => $call->follow_up_task_id === null ? null : (int) $call->follow_up_task_id,
            'entity' => $related instanceof Model
                ? CrmEntityMap::describe($related, $viewer)
                : null,
            'attachments_count' => (int) ($call->attachments_count
                ?? $call->media()->where('collection_name', CrmAttachments::COLLECTION)->count()),
            'can' => [
                'update' => $viewer->can('update', $call),
                'delete' => $viewer->can('delete', $call),
            ],
        ];
    }
}
