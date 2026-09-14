<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\TaxRegimeShift;
use App\Http\Requests\Crm\UpdateContractorTaxRegimeRequest;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\PersonalManager;
use App\Services\Crm\TaxRegime\ContractorTaxRegimeService;
use App\Services\Crm\TaxRegime\TaxRegimeRegistry;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Налоговый режим юрлиц партнёров: реестр, выгрузка, заполнение и подтверждение.
 *
 * Юрлицо резолвится через Company::visibleInCrm(): чужое отвечает 404, а не 403.
 */
class TaxRegimeController extends CrmController
{
    public function __construct(
        private readonly ContractorTaxRegimeService $regimes,
        private readonly TaxRegimeRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $byManager = $this->seesManagerBreakdown($request);
        $scope = CrmScope::fromRequest($request, $actor);
        $filters = $this->filters($request, $byManager);

        return Inertia::render('Crm/Pages/TaxRegimes/Index', [
            'rows' => $this->registry->paginate($actor, $scope, $filters),
            'summary' => $this->registry->summary($actor, $scope, $filters, $byManager),
            'filters' => $this->filtersForFront($filters, $scope),
            'options' => ContractorTaxRegimeService::options() + [
                'freshness' => TaxRegimeFreshness::options(),
                'shift' => TaxRegimeShift::options(),
                'active_days' => (int) config('crm_tax_regime.active_days'),
            ],
            'managers' => $byManager
                ? PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canSeeAll' => $this->seesDepartment($request),
            'canEdit' => $actor->can('crm-profile.edit'),
        ]);
    }

    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $filters = $this->filters($request, $this->seesManagerBreakdown($request));
        $rows = $this->registry->rows($actor, CrmScope::fromRequest($request, $actor), $filters);
        $year = CrmContractorTaxRegime::targetYear();

        return $exporter->stream(
            'crm-tax-regimes-'.now()->format('Y-m-d'),
            [
                'Партнёр', 'Менеджер', 'Контрагент', 'ИНН', 'КПП',
                'Режим сейчас', "План на {$year}", 'Изменение', 'Важность НДС', 'Состояние ответа',
                'Источник', 'Подтверждено', 'Кем', 'Комментарий',
            ],
            array_map(static fn (array $row): array => [
                $row['partner']['name'] ?? null,
                $row['manager'],
                $row['name'],
                $row['tax_id'],
                $row['tax_code'],
                $row['tax_regime']['current']['label'] ?? null,
                $row['tax_regime']['planned']['label'] ?? null,
                $row['tax_regime']['shift']['label'] ?? null,
                $row['tax_regime']['vat_preference']['label'] ?? null,
                $row['tax_regime']['freshness']['label'],
                $row['tax_regime']['source']['label'] ?? null,
                $row['tax_regime']['confirmed_at'],
                $row['tax_regime']['confirmed_by'],
                $row['tax_regime']['note'],
            ], $rows),
            'Налоговые режимы',
        );
    }

    public function update(UpdateContractorTaxRegimeRequest $request, int $contractor): RedirectResponse
    {
        $company = $this->contractor($request, $contractor);

        $this->regimes->save($company, $request->validated(), $this->crmActor($request));

        return back()->with('success', "Налоговый режим «{$this->name($company)}» сохранён");
    }

    public function confirm(Request $request, int $contractor): RedirectResponse
    {
        $company = $this->contractor($request, $contractor);

        $this->regimes->confirm($company, $this->crmActor($request));

        return back()->with('success', "Налоговый режим «{$this->name($company)}» подтверждён");
    }

    private function contractor(Request $request, int $id): Company
    {
        return Company::query()
            ->visibleInCrm($this->crmActor($request))
            ->with('taxRegime')
            ->findOrFail($id);
    }

    private function name(Company $company): string
    {
        return (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, bool $byManager): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'freshness' => ['nullable', Rule::enum(TaxRegimeFreshness::class)],
            'shift' => ['nullable', Rule::enum(TaxRegimeShift::class)],
            'current_regime' => ['nullable', Rule::enum(TaxRegime::class)],
            'planned_regime' => ['nullable', Rule::enum(TaxRegime::class)],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ], [], [
            'search' => 'поиск',
            'manager_id' => 'менеджер',
            'freshness' => 'состояние ответа',
            'shift' => 'изменение режима',
            'current_regime' => 'режим сейчас',
            'planned_regime' => 'план',
            'active' => 'только покупающие',
            'per_page' => 'размер страницы',
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        return [
            'search' => $search === '' ? null : $search,
            // Отбор по менеджеру — разрез по коллегам, он у того, кто видит их выручку.
            'manager_id' => $byManager ? ($validated['manager_id'] ?? null) : null,
            'freshness' => $validated['freshness'] ?? null,
            'shift' => $validated['shift'] ?? null,
            'current_regime' => $validated['current_regime'] ?? null,
            'planned_regime' => $validated['planned_regime'] ?? null,
            // По умолчанию — только покупающие: у остальных объёма под риском нет.
            'active' => $request->has('active') ? $request->boolean('active') : true,
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filtersForFront(array $filters, CrmScope $scope): array
    {
        return ['active' => $filters['active'] ? '1' : '0', 'scope' => $scope->value] + $filters;
    }
}
