<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\CrmComment;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\ClientTimelineService;
use App\Services\Crm\CrmEntityResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Комментарии и сквозная лента партнёра.
 *
 * Привязка резолвится через `CrmEntityResolver` — тем же путём, что и в вебе:
 * без этого комментарий стал бы способом писать в карточку чужого партнёра
 * в обход скоупа, а `entity_type` из запроса — способом добраться до
 * произвольной модели приложения.
 */
class CommentOperations
{
    use ResolvesCrmEntities;

    public function __construct(
        private readonly CrmEntityResolver $resolver,
        private readonly ClientTimelineService $timeline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function clientTimeline(User $actor, OperationInput $input): array
    {
        $client = $this->client($actor, $input);

        return $this->page($this->timeline->forClient(
            $client,
            $actor,
            min(100, max(1, (int) ($input->int('per_page') ?? 20))),
            $input->has('types') ? array_values($input->array('types')) : null,
            null,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $entity = $this->resolver->resolveForActor(
            $actor,
            (string) $input->string('entity_type'),
            (int) $input->int('entity_id'),
        );

        return $this->page(
            $this->timeline->forEntity($entity, $actor, min(100, max(1, (int) ($input->int('per_page') ?? 30))))
        );
    }

    /**
     * Страница ленты в форме, общей для всех списков API.
     *
     * Пагинатор наружу не отдаём: у него в JSON едут ссылки на веб-маршруты,
     * которые машинному потребителю бесполезны и только путают.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array<string, mixed>
     */
    private function page(LengthAwarePaginator $page): array
    {
        return [
            'data' => $page->items(),
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
        Gate::forUser($actor)->authorize('create', CrmComment::class);

        $entity = $this->resolver->resolveForActor(
            $actor,
            (string) $input->string('entity_type'),
            (int) $input->int('entity_id'),
        );

        $comment = new CrmComment([
            'body' => (string) $input->string('body'),
            'is_pinned' => $input->bool('is_pinned'),
        ]);
        $comment->commentable()->associate($entity);
        $comment->user_id = $actor->getKey();
        $comment->save();

        $comment->setRelation('author', $actor);
        $comment->setRelation('commentable', $entity);

        return $this->timeline->commentEntry($comment, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(User $actor, OperationInput $input): array
    {
        $comment = CrmComment::query()->findOrFail((int) $input->int('comment'));
        Gate::forUser($actor)->authorize('update', $comment);

        $comment->body = (string) $input->string('body');

        if ($input->has('is_pinned')) {
            $comment->is_pinned = $input->bool('is_pinned');
        }

        $comment->save();
        $comment->load(['author', 'commentable']);

        return $this->timeline->commentEntry($comment, $actor);
    }

    /**
     * Мягкое удаление своего комментария — единственная операция удаления,
     * оставленная агенту: запись остаётся в базе и восстановима, поэтому цена
     * ошибочного вызова здесь не «безвозвратно потеряно».
     *
     * @return array<string, mixed>
     */
    public function delete(User $actor, OperationInput $input): array
    {
        $comment = CrmComment::query()->findOrFail((int) $input->int('comment'));
        Gate::forUser($actor)->authorize('delete', $comment);

        $comment->delete();

        return ['deleted' => true, 'id' => (int) $comment->getKey()];
    }
}
