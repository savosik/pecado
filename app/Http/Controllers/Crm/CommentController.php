<?php

namespace App\Http\Controllers\Crm;

use App\Http\Requests\Crm\StoreCrmCommentRequest;
use App\Http\Requests\Crm\UpdateCrmCommentRequest;
use App\Models\CrmComment;
use App\Models\User;
use App\Services\Crm\ClientTimelineService;
use App\Services\Crm\CrmEntityResolver;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Комментарии CRM. Эндпоинты отдают JSON, а не Inertia-редиректы: один и тот же
 * компонент ленты встраивается и в карточку клиента, и в админские карточки заказа
 * и реализации — общая форма ответа избавляет от трёх вариантов интеграции.
 */
class CommentController extends CrmController
{
    public function __construct(
        private readonly CrmEntityResolver $resolver,
        private readonly ClientTimelineService $timeline,
    ) {}

    /**
     * Сквозная лента клиента: комментарии по нему, его заказам и отгрузкам.
     */
    public function timeline(Request $request, int $client): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmComment::class);

        // Резолвим через тот же scope, что и карточка: чужой клиент — 404.
        $clientModel = User::query()
            ->visibleInCrm($actor)
            ->findOrFail($client);

        return response()->json(
            $this->timeline->forClient($clientModel, $actor, $this->perPage($request, 20))
        );
    }

    /**
     * Комментарии одной сущности — для врезки в её карточку.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmComment::class);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::commentableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $entity = $this->resolver->resolveForActor(
            $actor,
            $validated['entity_type'],
            (int) $validated['entity_id'],
        );

        return response()->json(
            $this->timeline->forEntity($entity, $actor, $this->perPage($request, 30))
        );
    }

    public function store(StoreCrmCommentRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        // Доступ к сущности проверяется до создания: без этого комментарий стал бы
        // способом писать в карточку чужого клиента в обход скоупа.
        $entity = $this->resolver->resolveForActor(
            $actor,
            $request->string('entity_type')->value(),
            (int) $request->integer('entity_id'),
        );

        $comment = new CrmComment([
            'body' => $request->string('body')->value(),
            'is_pinned' => $request->boolean('is_pinned'),
        ]);
        $comment->commentable()->associate($entity);
        $comment->user_id = $actor->getKey();
        // client_user_id проставляет сама модель — единая точка на все пути создания.
        $comment->save();

        $comment->setRelation('author', $actor);
        $comment->setRelation('commentable', $entity);

        return response()->json($this->timeline->commentEntry($comment, $actor), 201);
    }

    public function update(UpdateCrmCommentRequest $request, CrmComment $comment): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertInScope($actor, $comment);
        Gate::authorize('update', $comment);

        $comment->fill($request->only(['body', 'is_pinned']));
        $comment->save();

        $comment->load(['author:id,name', 'commentable']);

        return response()->json($this->timeline->commentEntry($comment, $actor));
    }

    public function destroy(Request $request, CrmComment $comment): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertInScope($actor, $comment);
        Gate::authorize('delete', $comment);

        $comment->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Комментарий вне скоупа актора — 404, а не 403.
     *
     * Разделение намеренное: 404 скрывает сам факт существования переписки по чужому
     * клиенту, а 403 остаётся для понятного «клиент ваш, но комментарий не ваш».
     */
    private function assertInScope(User $actor, CrmComment $comment): void
    {
        abort_unless(
            $this->resolver->canAccessAttached($actor, $comment->client_user_id, $comment->commentable),
            404,
        );
    }

    private function perPage(Request $request, int $default): int
    {
        $perPage = (int) $request->input('per_page', $default);

        return min(max($perPage, 5), 100);
    }
}
