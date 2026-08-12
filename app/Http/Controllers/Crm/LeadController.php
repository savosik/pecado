<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\LeadFunnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Лиды: доска, карточка, перемещение по воронке (crm-26, crm-27).
 *
 * Доска отдаётся Inertia целиком, а перемещение — JSON: перетаскивание карточки
 * не должно перезагружать страницу, иначе на доске в сотню лидов каждый сдвиг
 * возвращал бы пользователя к началу.
 */
class LeadController extends CrmController
{
    public function __construct(
        private readonly CrmLeadService $leads,
        private readonly LeadFunnelService $funnel,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $scope = CrmScope::fromRequest($request, $actor);

        $stages = CrmLeadStage::query()->onBoard()->get();

        $leads = CrmLead::query()
            ->visibleTo($actor, $scope)
            ->with(['manager:id,name', 'stage:id,name,color', 'convertedUser:id,name,erp_name'])
            ->when($request->filled('search'), fn ($query) => $query->where(function ($inner) use ($request) {
                $like = '%'.trim((string) $request->input('search')).'%';
                $inner->where('name', 'like', $like)
                    ->orWhere('company_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            }))
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Crm/Pages/Leads/Index', [
            'stages' => $stages->map(fn (CrmLeadStage $stage): array => $this->stagePayload($stage))->all(),
            'leads' => $leads->map(fn (CrmLead $lead): array => $this->payload($lead))->all(),
            'funnel' => $this->funnel->summary($actor, $scope),
            'managers' => $this->seesDepartment($request)
                ? PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get()
                : [],
            'filters' => [
                'scope' => $scope->value,
                'search' => $request->input('search'),
            ],
            'canSeeDepartment' => $this->seesDepartment($request),
            'canEdit' => $actor->can('crm-leads.edit'),
            'canCreate' => $actor->can('crm-leads.create'),
            'canDelete' => $actor->can('crm-leads.delete'),
            'canManageStages' => $actor->can('crm-lead-stages.edit'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $data = $this->validated($request);

        $lead = $this->leads->create($data, $actor);

        return response()->json(['data' => $this->payload($lead->fresh())], 201);
    }

    public function update(Request $request, CrmLead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $lead->update($this->validated($request));

        return response()->json(['data' => $this->payload($lead->fresh())]);
    }

    /**
     * Перетаскивание карточки на доске.
     */
    public function move(Request $request, CrmLead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate([
            'stage_id' => ['required', 'integer', Rule::exists('crm_lead_stages', 'id')],
        ], [
            'stage_id.required' => 'Не указана стадия.',
            'stage_id.exists' => 'Такой стадии нет.',
        ]);

        $stage = CrmLeadStage::query()->findOrFail($validated['stage_id']);

        $this->leads->moveToStage($lead, $stage, $this->crmActor($request));

        return response()->json(['data' => $this->payload($lead->fresh())]);
    }

    /**
     * Привязать лида к появившемуся партнёру.
     */
    public function convert(Request $request, CrmLead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.required' => 'Выберите партнёра.',
            'user_id.exists' => 'Такого партнёра нет.',
        ]);

        $actor = $this->crmActor($request);

        // Привязать можно только к видимому партнёру: иначе через конверсию
        // открылся бы доступ к чужой карточке в обход скоупа.
        $client = User::query()->visibleInCrm($actor)->findOrFail($validated['user_id']);

        return response()->json(['data' => $this->payload($this->leads->convert($lead, $client, $actor))]);
    }

    public function destroy(Request $request, CrmLead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        abort_unless($this->crmActor($request)->can('crm-leads.delete'), 403);

        $lead->delete();

        return back();
    }

    /**
     * Лид коллеги правит тот, кто может действовать с чужими записями —
     * та же граница, что у задач.
     */
    private function authorizeLead(Request $request, CrmLead $lead): void
    {
        $actor = $this->crmActor($request);

        // Чужого лида для того, кто не видит отдел, не существует — 404,
        // как и у партнёра.
        abort_unless(
            CrmLead::query()->visibleTo($actor)->whereKey($lead->getKey())->exists(),
            404,
        );

        $mine = $lead->manager_id === null
            || (int) $lead->manager_id === (int) $actor->managerProfile?->id;

        abort_unless($mine || $actor->can('crm-department.edit'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'messenger' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', 'integer', 'exists:personal_managers,id'],
            'stage_id' => ['nullable', 'integer', 'exists:crm_lead_stages,id'],
            'qualified_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'expected_close_at' => ['nullable', 'date'],
            'decision_maker' => ['nullable', 'string', 'max:255'],
            'interests' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Введите имя или название организации.',
            'email.email' => 'Проверьте адрес электронной почты.',
        ]);

        // Минимум лида — имя и любой контакт. Требовать конкретный означало бы
        // не дать завести лида, у которого есть только телефон.
        if (blank($data['phone'] ?? null) && blank($data['email'] ?? null) && blank($data['messenger'] ?? null)) {
            throw ValidationException::withMessages([
                'phone' => 'Укажите хотя бы один контакт: телефон, email или мессенджер.',
            ]);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function stagePayload(CrmLeadStage $stage): array
    {
        return [
            'id' => (int) $stage->getKey(),
            'name' => $stage->name,
            'color' => $stage->color,
            'position' => $stage->position,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CrmLead $lead): array
    {
        return [
            'id' => (int) $lead->getKey(),
            'name' => $lead->name,
            'company_name' => $lead->company_name,
            'contact' => $lead->primaryContact(),
            'phone' => $lead->phone,
            'email' => $lead->email,
            'messenger' => $lead->messenger,
            'source' => $lead->source,
            'stage_id' => $lead->stage_id === null ? null : (int) $lead->stage_id,
            'manager' => $lead->manager === null ? null : [
                'id' => (int) $lead->manager->getKey(),
                'name' => $lead->manager->name,
            ],
            'qualified_amount' => $lead->qualified_amount === null ? null : (float) $lead->qualified_amount,
            'currency_code' => $lead->currency_code,
            'expected_close_at' => $lead->expected_close_at?->toDateString(),
            'decision_maker' => $lead->decision_maker,
            'interests' => $lead->interests,
            'notes' => $lead->notes,
            'lost_reason' => $lead->lost_reason,
            'days_on_stage' => $lead->daysOnStage(),
            'converted_user' => $lead->convertedUser === null ? null : [
                'id' => (int) $lead->convertedUser->getKey(),
                'name' => $lead->convertedUser->display_name,
            ],
            'created_at' => $lead->created_at?->format('d.m.Y'),
        ];
    }
}
