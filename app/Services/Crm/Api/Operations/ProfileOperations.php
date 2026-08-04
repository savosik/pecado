<?php

namespace App\Services\Crm\Api\Operations;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\ClientLifecycleService;
use App\Services\Crm\ClientProfileService;
use Spatie\Tags\Tag;

/**
 * Профиль клиента и его жизненный статус — то, что знает менеджер, но не знает 1С.
 *
 * Лояльность (`users.client_status_id`) здесь не трогается ни одной операцией:
 * ею владеет 1С и перезапишет следующим `partner.updated`. Агенту про это
 * сказано в инструкциях сервера — иначе он честно попробует «повысить статус».
 */
class ProfileOperations
{
    use ResolvesCrmEntities;

    /** Поля профиля, доступные для записи через API. */
    private const FIELDS = [
        'decision_maker_name',
        'decision_maker_role',
        'decision_maker_contact',
        'decision_process',
        'payment_behavior',
        'payment_terms',
        'order_cycle_days',
        'preferred_channel',
        'sentiment',
        'notes_md',
        'interests',
    ];

    public function __construct(
        private readonly ClientProfileService $profiles,
        private readonly ClientLifecycleService $lifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, OperationInput $input): array
    {
        $client = $this->client($actor, $input);

        return $this->payload($client);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(User $actor, OperationInput $input): array
    {
        $client = $this->client($actor, $input);

        // only(): сервис различает «поле не трогали» и «поле очистили», поэтому
        // передаём ровно те ключи, которые пришли, а не весь список полей.
        $this->profiles->update($client, $input->only(self::FIELDS), $actor);

        return $this->payload($client->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function changeLifecycle(User $actor, OperationInput $input): array
    {
        $client = $this->client($actor, $input);
        $status = ClientLifecycleStatus::from((string) $input->string('lifecycle_status'));

        $this->lifecycle->change($client, $status, $actor, $input->string('reason'));

        return [
            'client_id' => (int) $client->getKey(),
            'lifecycle_status' => $status->value,
            'label' => $status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lifecycleHistory(User $actor, OperationInput $input): array
    {
        $client = $this->client($actor, $input);

        return ['data' => $this->lifecycle->history($client, (int) ($input->int('limit') ?? 20))];
    }

    /**
     * Справочник интересов — те же теги своего типа, что и в подсказках формы.
     *
     * @return array<string, mixed>
     */
    public function interests(User $actor, OperationInput $input): array
    {
        $query = trim((string) $input->string('query', ''));

        $tags = Tag::query()
            ->where('type', User::INTEREST_TAG_TYPE)
            ->when($query !== '', fn ($q) => $q->containing($query))
            ->orderBy('name')
            ->take(20)
            ->get()
            ->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => (string) $tag->name])
            ->all();

        return ['data' => $tags];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $client): array
    {
        $profile = $this->profiles->forClient($client);

        return [
            'client_id' => (int) $client->getKey(),
            'decision_maker_name' => $profile->decision_maker_name,
            'decision_maker_role' => $profile->decision_maker_role,
            'decision_maker_contact' => $profile->decision_maker_contact,
            'decision_process' => $profile->decision_process,
            'payment_behavior' => $profile->payment_behavior?->value,
            'payment_terms' => $profile->payment_terms,
            'order_cycle_days' => $profile->order_cycle_days,
            'preferred_channel' => $profile->preferred_channel?->value,
            'sentiment' => $profile->sentiment?->value,
            'lifecycle_status' => $profile->lifecycle_status->value,
            'notes_md' => $profile->notes_md,
            'interests' => $this->profiles->interests($client),
            'lifecycle_changed_at' => $profile->lifecycle_changed_at?->toIso8601String(),
        ];
    }
}
