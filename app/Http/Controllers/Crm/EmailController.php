<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Enums\Crm\EmailStatus;
use App\Enums\Crm\MailFolder;
use App\Enums\Crm\MailTopic;
use App\Http\Requests\Crm\StoreCrmEmailRequest;
use App\Http\Requests\Crm\UpdateCrmEmailRequest;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\User;
use App\Services\Crm\CrmEmailService;
use App\Services\Crm\CrmEntityResolver;
use App\Services\Crm\Mail\MailOccasions;
use App\Services\Crm\Mail\MailTagBuilder;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Письма менеджеров: журнал и составление.
 *
 * Журнал — Inertia-страница, всё остальное JSON: тот же диалог написания открывается
 * из карточки партнёра и из админских карточек заказа и реализации.
 */
class EmailController extends CrmController
{
    public function __construct(
        private readonly CrmEmailService $emails,
        private readonly CrmEntityResolver $resolver,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        $filters = $this->validateFilters($request);
        $scope = CrmScope::fromRequest($request, $actor);
        $folder = MailFolder::tryFrom((string) $filters['folder']) ?? MailFolder::DRAFTS;

        $query = $this->emails->visibleTo($actor, $scope)
            ->inFolder($folder)
            ->with(['author:id,name', 'related', 'autoSentRule:id,name', 'deliveries'])
            ->withCount(['media as attachments_count' => fn ($media) => $media->where(
                'collection_name',
                CrmAttachments::COLLECTION,
            )]);

        $this->applyFilters($query, $filters);

        $paginator = $query->latest('id')->paginate($filters['per_page'])->withQueryString();

        return Inertia::render('Crm/Pages/Emails/Index', [
            'emails' => $paginator->through(fn (CrmEmail $email) => $this->emails->payload($email, $actor)),
            'filters' => [...$filters, 'folder' => $folder->value, 'scope' => $scope->value],
            'folders' => $this->folders($actor, $scope, $filters),
            'canSeeDepartment' => $this->seesDepartment($request),
            'statuses' => EmailStatus::optionsWithColor(),
            'topics' => MailTopic::options(),
            'templates' => $this->templates(),
            'outboundEnabled' => $this->emails->outboundEnabled(),
            // Сводка непойманного — не отчёт ради отчёта: по ней видно, чего
            // система умеет больше, чем от неё сейчас берут.
            'unmatchedSummary' => $folder === MailFolder::UNMATCHED
                ? $this->unmatchedSummary($actor, $scope)
                : null,
            'canManageRules' => $actor->can('crm-emails.edit'),
            // Ссылка из ленты партнёра ведёт сюда с ?email=ID — журнал откроет письмо.
            'openEmailId' => $request->integer('email') ?: null,
        ]);
    }

    /**
     * Массовая отправка выбранных писем — рабочая операция «Черновиков».
     */
    public function bulkSend(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $ids = $this->validateIds($request);
        $sent = 0;
        $failed = [];

        foreach ($this->lettersByIds($actor, $ids) as $email) {
            if (! Gate::forUser($actor)->allows('send', $email)) {
                continue;
            }

            try {
                $this->emails->send($email);
                $sent++;
            } catch (RuntimeException $exception) {
                $failed[] = $exception->getMessage();
            }
        }

        return response()->json([
            'sent' => $sent,
            'message' => $failed === []
                ? 'Отправлено писем: '.$sent
                : 'Отправлено: '.$sent.'. Не удалось: '.implode('; ', array_unique($failed)),
        ]);
    }

    /**
     * Массовое удаление выбранных черновиков.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $ids = $this->validateIds($request);
        $deleted = 0;

        foreach ($this->lettersByIds($actor, $ids) as $email) {
            if (! Gate::forUser($actor)->allows('delete', $email)) {
                continue;
            }

            $email->delete();
            $deleted++;
        }

        return response()->json([
            'deleted' => $deleted,
            'message' => 'Удалено писем: '.$deleted,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function validateIds(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return array_map('intval', $validated['ids']);
    }

    /**
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, CrmEmail>
     */
    private function lettersByIds(User $actor, array $ids)
    {
        return $this->emails->visibleTo($actor)->whereIn('id', $ids)->get();
    }

    /**
     * Папки со счётчиками. Счётчик считается в том же охвате и с теми же
     * фильтрами, что и список, — иначе цифра в папке и число строк в ней
     * расходятся, и доверие к обеим пропадает.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function folders(User $actor, CrmScope $scope, array $filters): array
    {
        return array_map(function (array $option) use ($actor, $scope, $filters): array {
            $folder = MailFolder::from($option['value']);

            $query = $this->emails->visibleTo($actor, $scope)->inFolder($folder);
            $this->applyFilters($query, $filters);

            return [...$option, 'count' => $query->count()];
        }, MailFolder::options());
    }

    /**
     * Что прошло мимо фильтров за время хранения — с разбивкой по поводам.
     *
     * @return array<string, mixed>
     */
    private function unmatchedSummary(User $actor, CrmScope $scope): array
    {
        $rows = $this->emails->visibleTo($actor, $scope)
            ->inFolder(MailFolder::UNMATCHED)
            ->selectRaw('origin_event, count(*) as total')
            ->groupBy('origin_event')
            ->orderByDesc('total')
            ->get();

        return [
            'retention_days' => (int) config('mail_stream.unmatched_retention_days', 14),
            'rows' => $rows->map(fn ($row): array => [
                'event' => $row->origin_event,
                'label' => app(MailOccasions::class)->label((string) $row->origin_event),
                'total' => (int) $row->total,
                // Метка, с которой имеет смысл начать правило: по ней кнопка
                // «настроить» открывает форму с уже набранным условием.
                'tag' => app(MailTagBuilder::class)->occasionTags((string) $row->origin_event)[0] ?? null,
            ])->all(),
        ];
    }

    /**
     * @param  Builder<CrmEmail>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['client_id'] !== null) {
            $query->where('client_user_id', $filters['client_id']);
        }

        if ($filters['author_id'] !== null) {
            $query->where('user_id', $filters['author_id']);
        }

        if ($filters['origin'] !== null) {
            $query->where('origin', $filters['origin']);
        }

        $topic = MailTopic::tryFrom((string) $filters['topic']);

        if ($topic !== null) {
            $topic->apply($query);
        }

        // Отбор по метке — целиком, а не куском: метка и есть то, за что
        // цепляется фильтр, и искать её подстрокой означало бы ловить чужое.
        //
        // Иголка кодируется тем же json_encode, что и сама колонка: кириллица
        // лежит в базе экранированной (\u043e…), и поиск сырой строкой
        // не нашёл бы ничего — молча и навсегда.
        if ($filters['tag'] !== null && $filters['tag'] !== '') {
            $needle = json_encode($filters['tag']);

            $query->where('tags', 'like', '%'.$needle.'%');
        }

        if ($filters['search'] !== null && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $inner) => $inner
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('body_html', 'like', "%{$search}%")
                ->orWhere('to', 'like', "%{$search}%"));
        }
    }

    /**
     * Письма одной сущности — для врезки в её карточке.
     */
    public function list(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $entity = $this->resolver->resolveForActor(
            $actor,
            $validated['entity_type'],
            (int) $validated['entity_id'],
        );

        $paginator = $this->emails->visibleTo($actor)
            ->where('related_type', $entity::class)
            ->where('related_id', $entity->getKey())
            ->with(['author:id,name', 'related'])
            ->withCount(['media as attachments_count' => fn ($media) => $media->where(
                'collection_name',
                CrmAttachments::COLLECTION,
            )])
            ->latest('id')
            ->paginate(30);

        return response()->json(
            $paginator->through(fn (CrmEmail $email) => $this->emails->payload($email, $actor))
        );
    }

    /**
     * Справочники диалога: заготовки писем и состояние флага отправки.
     */
    public function options(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CrmEmail::class);

        return response()->json([
            'templates' => $this->templates(),
            'outbound_enabled' => $this->emails->outboundEnabled(),
            'reply_to' => $this->crmActor($request)->email,
        ]);
    }

    /**
     * Заготовка с раскрытыми подстановками — тема и тело для формы.
     */
    public function template(Request $request, CrmEmailTemplate $template): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('create', CrmEmail::class);

        $validated = $request->validate([
            'client_id' => ['nullable', 'integer'],
        ]);

        $client = isset($validated['client_id'])
            ? User::query()->visibleInCrm($actor)->find($validated['client_id'])
            : null;

        return response()->json($this->emails->applyTemplate($template, $actor, $client));
    }

    public function show(Request $request, CrmEmail $email): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('view', $email);

        $email->load(['author:id,name', 'related', 'deliveries']);

        return response()->json($this->emails->payload($email, $actor));
    }

    public function store(StoreCrmEmailRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        // Доступ к сущности проверяется до создания: письмо не должно становиться
        // способом узнать о существовании чужого партнёра и его документов.
        $related = $request->filled('entity_type')
            ? $this->resolver->resolveForActor(
                $actor,
                $request->string('entity_type')->value(),
                (int) $request->integer('entity_id'),
            )
            : null;

        $email = $this->emails->createDraft($actor, $request->validated(), $related);
        $email->load(['author:id,name', 'related']);

        return response()->json($this->emails->payload($email, $actor), 201);
    }

    public function update(UpdateCrmEmailRequest $request, CrmEmail $email): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('update', $email);

        $this->emails->update($email, $request->validated());
        $email->load(['author:id,name', 'related']);

        return response()->json($this->emails->payload($email, $actor));
    }

    /**
     * Отправить письмо.
     *
     * Выключенный флаг — это 422 с внятным текстом, а не 403: сотрудник ничего
     * не нарушил, просто отправка ещё не включена администратором.
     */
    public function send(Request $request, CrmEmail $email): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('send', $email);

        try {
            $this->emails->send($email);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // Перечитываем: на синхронной очереди задание уже отработало, и письмо
        // на самом деле «отправлено», а не «в очереди».
        $email->refresh()->load(['author:id,name', 'related']);

        return response()->json($this->emails->payload($email, $actor));
    }

    public function destroy(Request $request, CrmEmail $email): JsonResponse
    {
        Gate::authorize('delete', $email);

        $email->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function templates(): array
    {
        return CrmEmailTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CrmEmailTemplate $template): array => [
                'id' => (int) $template->getKey(),
                'name' => $template->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'folder' => ['nullable', Rule::enum(MailFolder::class)],
            'topic' => ['nullable', Rule::enum(MailTopic::class)],
            'tag' => ['nullable', 'string', 'max:190'],
            'origin' => ['nullable', Rule::in([CrmEmail::ORIGIN_MANUAL, CrmEmail::ORIGIN_SYSTEM])],
            'client_id' => ['nullable', 'integer'],
            'author_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer'],
        ]);

        return [
            'folder' => $validated['folder'] ?? MailFolder::DRAFTS->value,
            'topic' => $validated['topic'] ?? null,
            'tag' => $validated['tag'] ?? null,
            'origin' => $validated['origin'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'author_id' => $validated['author_id'] ?? null,
            'search' => $validated['search'] ?? null,
            'per_page' => min(max((int) ($validated['per_page'] ?? 20), 5), 100),
        ];
    }
}
