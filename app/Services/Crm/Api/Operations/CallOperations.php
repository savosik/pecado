<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\CrmCall;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\CrmCallService;
use App\Services\Crm\CrmEntityResolver;
use Illuminate\Support\Facades\Gate;

/**
 * Журнал звонков: список и запись состоявшегося разговора.
 *
 * Телефония не подключена (см. crm-19), поэтому звонок записывается вручную —
 * и агент здесь на равных с человеком: он может занести итог разговора,
 * который менеджер ему продиктовал, вместе со следующим шагом.
 */
class CallOperations
{
    public function __construct(
        private readonly CrmCallService $calls,
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmCall::class);

        $query = $this->calls->visibleTo($actor)->with(['author:id,name', 'related']);

        if ($input->has('client_id')) {
            $query->where('client_user_id', $input->int('client_id'));
        }

        if ($input->has('result')) {
            $query->where('result', $input->string('result'));
        }

        $page = $query->latest('started_at')
            ->paginate(min(100, max(1, (int) ($input->int('per_page') ?? 30))));

        return [
            'data' => $page->getCollection()
                ->map(fn (CrmCall $call) => $this->calls->payload($call, $actor))
                ->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function create(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('create', CrmCall::class);

        $related = $input->has('entity_type')
            ? $this->resolver->resolveForActor(
                $actor,
                (string) $input->string('entity_type'),
                (int) $input->int('entity_id'),
            )
            : null;

        $result = $this->calls->log(
            $actor,
            $input->only(['direction', 'result', 'phone', 'contact_name', 'summary', 'started_at', 'duration_sec', 'follow_up']),
            $related,
        );

        $result['call']->load(['author:id,name', 'related']);

        return [
            'call' => $this->calls->payload($result['call'], $actor),
            'follow_up_task_id' => $result['follow_up'] === null
                ? null
                : (int) $result['follow_up']->getKey(),
        ];
    }
}
