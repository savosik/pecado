<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use App\Enums\Crm\CrmScope;
use App\Http\Requests\Crm\StoreCrmCallRequest;
use App\Http\Requests\Crm\UpdateCrmCallRequest;
use App\Models\CrmCall;
use App\Services\Crm\CrmCallService;
use App\Services\Crm\CrmEntityResolver;
use App\Services\Crm\CrmTaskService;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Журнал звонков.
 *
 * Только JSON: звонок записывается из диалога в таблице партнёров и из ленты —
 * в обоих местах Inertia-редирект увёл бы менеджера со страницы посреди работы.
 */
class CallController extends CrmController
{
    public function __construct(
        private readonly CrmCallService $calls,
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * Звонки по сущности — для врезки в её карточку.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmCall::class);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        // Резолв через общий резолвер: чужая сущность даёт 404, а не пустой список.
        $entity = $this->resolver->resolveForActor(
            $actor,
            $validated['entity_type'],
            (int) $validated['entity_id'],
        );

        $clientId = CrmEntityMap::clientIdFor($entity);

        $query = $this->calls->visibleTo($actor, CrmScope::fromRequest($request, $actor))
            ->with('author:id,name', 'related')
            ->withCount(['media as attachments_count' => fn ($media) => $media->where(
                'collection_name',
                CrmAttachments::COLLECTION,
            )]);

        // По партнёру показываем все его звонки, по документу — только его собственные:
        // в карточке заказа история всех разговоров с партнёром была бы шумом.
        $validated['entity_type'] === CrmEntityMap::CLIENT && $clientId !== null
            ? $query->forClient($clientId)
            : $query->where('related_type', $entity::class)->where('related_id', $entity->getKey());

        $perPage = min(max((int) $request->input('per_page', 30), 5), 100);

        return response()->json(
            $query->chronological()->paginate($perPage)->through(
                fn (CrmCall $call): array => $this->calls->payload($call, $actor)
            )
        );
    }

    /**
     * Справочники диалога звонка.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'directions' => CallDirection::options(),
            'results' => CallResult::options(),
            'assignees' => app(CrmTaskService::class)->assignableUsers(),
        ]);
    }

    public function store(StoreCrmCallRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $data = $request->validated();

        $entity = isset($data['entity_type'], $data['entity_id'])
            ? $this->resolver->resolveForActor($actor, $data['entity_type'], (int) $data['entity_id'])
            : null;

        ['call' => $call, 'follow_up' => $followUp] = $this->calls->log($actor, $data, $entity);

        $call->load('author:id,name', 'related');

        return response()->json([
            'call' => $this->calls->payload($call, $actor),
            'follow_up' => $followUp === null
                ? null
                : app(CrmTaskService::class)->payload($followUp->load('author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'tags', 'related'), $actor),
        ], 201);
    }

    public function update(UpdateCrmCallRequest $request, CrmCall $call): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('update', $call);

        $this->calls->update($call, $request->validated());
        $call->load('author:id,name', 'related');

        return response()->json($this->calls->payload($call, $actor));
    }

    public function destroy(Request $request, CrmCall $call): JsonResponse
    {
        Gate::authorize('delete', $call);

        $call->delete();

        return response()->json(status: 204);
    }
}
