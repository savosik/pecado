<?php

namespace App\Http\Controllers\Crm;

use App\Http\Requests\Crm\StoreMailRuleRequest;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\CrmMailRuleHit;
use App\Models\User;
use App\Services\Crm\CrmEntitySearch;
use App\Services\Crm\Mail\MailFieldCatalog;
use App\Services\Crm\Mail\MailRuleService;
use App\Services\Crm\Mail\MailTagBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Правила-фильтры над потоком писем.
 *
 * Отдельного раздела меню у правил нет: это вкладка внутри «Писем». Дробление
 * на разделы — ровно то, из-за чего предыдущий подход оказался непонятным.
 */
class MailRuleController extends CrmController
{
    public function __construct(private readonly MailRuleService $rules) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:190'],
            'author_id' => ['nullable', 'integer'],
            'client' => ['nullable', 'string', 'max:190'],
            'only_auto' => ['nullable', 'boolean'],
        ]);

        $query = CrmMailRule::query()->with(['author:id,name', 'clients:id,name,erp_name']);

        $this->applyRuleFilters($query, $filters);

        $rules = $query
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (CrmMailRule $rule): array => $this->rules->payload($rule))
            ->all();

        return Inertia::render('Crm/Pages/Emails/Rules', [
            'rules' => $rules,
            'filters' => [
                'search' => $filters['search'] ?? null,
                'author_id' => $filters['author_id'] ?? null,
                'client' => $filters['client'] ?? null,
                'only_auto' => (bool) ($filters['only_auto'] ?? false),
            ],
            'authors' => $this->authors(),
            'fieldGroups' => MailFieldCatalog::groups(),
            'operators' => MailFieldCatalog::operators(),
            'unaryOperators' => MailFieldCatalog::unaryOperators(),
            'tagSuggestions' => app(MailTagBuilder::class)->suggestions(),
            'autoSendEnabled' => (bool) config('mail_stream.autosend'),
            'applyToOldDays' => (int) config('mail_stream.apply_to_old_days', 14),
            'streamEnabled' => (bool) config('mail_stream.enabled'),
            'canManage' => $actor->can('crm-emails.edit'),
            // Переход из сводки «Мимо фильтров»: форма открывается с уже
            // набранным условием, чтобы менеджер не переписывал метку руками.
            'prefillTag' => $request->string('tag')->value() ?: null,
        ]);
    }

    /**
     * Отбор правил. Их станет много, и список без фильтров быстро перестанет
     * быть списком.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CrmMailRule>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyRuleFilters($query, array $filters): void
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            // Ищем и по названию, и по условиям, и по адресам: менеджер помнит
            // правило по-разному — «то, что про акты», «то, что на буханову».
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('recipients', 'like', '%'.trim(json_encode($search), '"').'%')
                    ->orWhere('conditions', 'like', '%'.trim(json_encode($search), '"').'%');
            });
        }

        if (filled($filters['author_id'] ?? null)) {
            $query->where('user_id', (int) $filters['author_id']);
        }

        if (! empty($filters['only_auto'])) {
            $query->where('auto_send', true);
        }

        // Отбор по партнёру отвечает сразу на два вопроса: на что партнёр
        // подписан явно и что про него реально уходило. Одного факта мало —
        // свежая подписка ещё ничего не поймала; одного замысла мало —
        // глобальное правило подписки не имеет, а письма шлёт.
        if (filled($filters['client'] ?? null)) {
            $name = $filters['client'];

            $clientIds = User::query()
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$name}%")
                    ->orWhere('erp_name', 'like', "%{$name}%"))
                ->limit(200)
                ->pluck('id');

            $query->where(fn ($inner) => $inner
                ->whereHas('clients', fn ($clients) => $clients->whereIn('users.id', $clientIds))
                ->orWhereIn('id', CrmMailRuleHit::query()
                    ->select('rule_id')
                    ->whereIn('crm_email_id', CrmEmail::query()
                        ->select('id')
                        ->whereIn('client_user_id', $clientIds))));
        }
    }

    /**
     * Кто заводил правила — для выпадающего списка отбора.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function authors(): array
    {
        return User::query()
            ->whereIn('id', CrmMailRule::query()->select('user_id')->whereNotNull('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => (int) $user->getKey(), 'name' => $user->name])
            ->all();
    }

    /**
     * Реальные письма под условиями — сердце формы правила.
     */
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        $validated = $request->validate([
            'match' => ['nullable', 'string'],
            'conditions' => ['present', 'array', 'max:20'],
            'client_ids' => ['nullable', 'array', 'max:1000'],
            'client_ids.*' => ['integer'],
        ]);

        $conditions = $this->rules->buildConditions(
            (string) ($validated['match'] ?? 'all'),
            (array) $validated['conditions'],
        );

        return response()->json($this->rules->preview(
            $actor,
            $conditions,
            10,
            $this->clientIds($actor, (array) ($validated['client_ids'] ?? [])),
        ));
    }

    /**
     * Партнёры для выбора подписчиков — тот же поиск, что в привязке контактов.
     */
    public function clientOptions(Request $request, CrmEntitySearch $search): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        return response()->json([
            'options' => $search->clients($actor, trim((string) $request->string('search'))),
        ]);
    }

    /**
     * Отсев чужих партнёров.
     *
     * Менеджер не должен подписать на правило клиента, которого не видит:
     * иначе через список подписчиков утекают имена чужих клиентов.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function clientIds(User $actor, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->visibleInCrm($actor)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function store(StoreMailRuleRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $rule = new CrmMailRule($this->attributes($request));
        $rule->user_id = (int) $actor->getKey();
        $rule->save();

        $this->syncClients($request, $rule, $actor);

        // Задним числом ничего не подбираем: правило работает вперёд. Захочет
        // менеджер поднять уже собранные письма — нажмёт «применить к старым»,
        // увидев в превью, что именно ловится.
        return response()->json([
            'rule' => $this->rules->payload($this->fresh($rule)),
        ], 201);
    }

    public function update(StoreMailRuleRequest $request, CrmMailRule $rule): JsonResponse
    {
        $actor = $this->crmActor($request);

        $rule->fill($this->attributes($request))->save();

        $this->syncClients($request, $rule, $actor);

        return response()->json([
            'rule' => $this->rules->payload($this->fresh($rule)),
        ]);
    }

    /**
     * Пересобрать список подписчиков правила.
     *
     * Пустой список — это «все партнёры», а не «никто»: правило без адресной
     * части остаётся глобальным фильтром, каким было до подписок.
     */
    private function syncClients(StoreMailRuleRequest $request, CrmMailRule $rule, User $actor): void
    {
        $ids = $this->clientIds($actor, (array) $request->validated('client_ids', []));
        $current = $rule->clients()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();

        // Не sync(): он переписал бы автора и дату подписки у тех, кто уже
        // был подписан. Кто и когда подписал партнёра — это история, а не
        // побочный результат сохранения формы.
        $rule->clients()->detach(array_diff($current, $ids));

        foreach (array_diff($ids, $current) as $id) {
            $rule->clients()->attach($id, [
                'created_by_user_id' => (int) $actor->getKey(),
                'created_at' => now(),
            ]);
        }
    }

    private function fresh(CrmMailRule $rule): CrmMailRule
    {
        return $rule->refresh()->load('clients:id,name,erp_name', 'author:id,name');
    }

    public function toggle(Request $request, CrmMailRule $rule): JsonResponse
    {
        $rule->is_active = ! $rule->is_active;
        $rule->save();

        return response()->json([
            'rule' => $this->rules->payload($rule->refresh()),
        ]);
    }

    /**
     * Применить правило к письмам, собранным до его создания.
     *
     * Отдельным действием, а не автоматически при сохранении: менеджер видит
     * в превью, что ловится, и сам решает, надо ли поднимать уже собранное.
     */
    public function applyToOld(Request $request, CrmMailRule $rule): JsonResponse
    {
        $picked = $this->rules->applyToOld($rule);

        return response()->json([
            'rule' => $this->rules->payload($rule->refresh()),
            'picked' => $picked,
            'message' => $picked === 0
                ? 'Среди уже собранных писем под правило не подошло ни одно'
                : 'Правило применено к письмам: '.$picked,
        ]);
    }

    public function destroy(Request $request, CrmMailRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StoreMailRuleRequest $request): array
    {
        $validated = $request->validated();

        return [
            'name' => $validated['name'],
            'conditions' => $this->rules->buildConditions(
                (string) $validated['match'],
                (array) $validated['conditions'],
            ),
            'recipients' => $this->normalize($validated['recipients'] ?? []),
            'cc' => $this->normalize($validated['cc'] ?? []) ?: null,
            'auto_send' => (bool) ($validated['auto_send'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'throttle_minutes' => $validated['throttle_minutes'] ?? null,
        ];
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    private function normalize(array $addresses): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($address): string => trim((string) $address), $addresses),
            fn (string $address): bool => $address !== '',
        )));
    }
}
