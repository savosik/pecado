<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\CrmEmail;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\CrmEmailService;
use App\Services\Crm\CrmEntityResolver;
use Illuminate\Support\Facades\Gate;

/**
 * Письма: журнал, черновик и отправка.
 *
 * Черновик создаётся и при выключенной отправке — так же, как в вебе: агент
 * готовит текст, менеджер решает, уходит ли письмо. Сама отправка гейтится тем
 * же флагом `MAIL_FEATURE_CRM_OUTBOUND`, что и кнопка на экране; отдельного
 * «агентского» обхода флага нет.
 */
class EmailOperations
{
    public function __construct(
        private readonly CrmEmailService $emails,
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmEmail::class);

        $query = $this->emails->visibleTo($actor)->with(['author:id,name', 'related']);

        if ($input->has('status')) {
            $query->where('status', $input->string('status'));
        }

        if ($input->has('client_id')) {
            $query->where('client_user_id', $input->int('client_id'));
        }

        $page = $query->latest('id')
            ->paginate(min(100, max(1, (int) ($input->int('per_page') ?? 30))));

        return [
            'data' => $page->getCollection()
                ->map(fn (CrmEmail $email) => $this->emails->payload($email, $actor))
                ->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'outbound_enabled' => $this->emails->outboundEnabled(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, OperationInput $input): array
    {
        return $this->emails->payload($this->email($actor, $input), $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function draft(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('create', CrmEmail::class);

        $related = $input->has('entity_type')
            ? $this->resolver->resolveForActor(
                $actor,
                (string) $input->string('entity_type'),
                (int) $input->int('entity_id'),
            )
            : null;

        $email = $this->emails->createDraft(
            $actor,
            $input->only(['to', 'cc', 'reply_to', 'subject', 'body_html']),
            $related,
        );
        $email->load(['author:id,name', 'related']);

        return $this->emails->payload($email, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function send(User $actor, OperationInput $input): array
    {
        $email = $this->email($actor, $input);
        Gate::forUser($actor)->authorize('update', $email);

        $this->emails->send($email);
        $email->load(['author:id,name', 'related']);

        return $this->emails->payload($email, $actor);
    }

    private function email(User $actor, OperationInput $input): CrmEmail
    {
        return $this->emails->visibleTo($actor)
            ->with(['author:id,name', 'related'])
            ->findOrFail((int) $input->int('email'));
    }
}
