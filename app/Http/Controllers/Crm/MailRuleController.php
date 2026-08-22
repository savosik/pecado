<?php

namespace App\Http\Controllers\Crm;

use App\Http\Requests\Crm\StoreMailRuleRequest;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
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

        $rules = CrmMailRule::query()
            ->with('author:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (CrmMailRule $rule): array => $this->rules->payload($rule))
            ->all();

        return Inertia::render('Crm/Pages/Emails/Rules', [
            'rules' => $rules,
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
     * Реальные письма под условиями — сердце формы правила.
     */
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmEmail::class);

        $validated = $request->validate([
            'match' => ['nullable', 'string'],
            'conditions' => ['present', 'array', 'max:20'],
        ]);

        $conditions = $this->rules->buildConditions(
            (string) ($validated['match'] ?? 'all'),
            (array) $validated['conditions'],
        );

        return response()->json($this->rules->preview($actor, $conditions));
    }

    public function store(StoreMailRuleRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $rule = new CrmMailRule($this->attributes($request));
        $rule->user_id = (int) $actor->getKey();
        $rule->save();

        // Задним числом ничего не подбираем: правило работает вперёд. Захочет
        // менеджер поднять уже собранные письма — нажмёт «применить к старым»,
        // увидев в превью, что именно ловится.
        return response()->json([
            'rule' => $this->rules->payload($rule->refresh()),
        ], 201);
    }

    public function update(StoreMailRuleRequest $request, CrmMailRule $rule): JsonResponse
    {
        $rule->fill($this->attributes($request))->save();

        return response()->json([
            'rule' => $this->rules->payload($rule->refresh()),
        ]);
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
