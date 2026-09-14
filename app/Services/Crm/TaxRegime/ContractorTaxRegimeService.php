<?php

namespace App\Services\Crm\TaxRegime;

use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\TaxRegimeFreshness;
use App\Enums\Crm\TaxRegimeShift;
use App\Enums\Crm\VatPreference;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\CrmContractorTaxRegimeHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Единственная точка изменения налогового режима юрлица.
 *
 * Каждое сохранение и подтверждение пишет журнал и закрывает задачу партнёра,
 * если ответов стало достаточно, — независимо от того, кто ответил: менеджер
 * в CRM или сам клиент в опросе на сайте.
 */
class ContractorTaxRegimeService
{
    public function __construct(private readonly TaxRegimeTaskPlanner $planner) {}

    /**
     * Ответ менеджера со слов партнёра.
     *
     * @param  array<string, mixed>  $data  current_regime, planned_regime, vat_preference?, note?
     */
    public function save(Company $company, array $data, User $actor): CrmContractorTaxRegime
    {
        return DB::transaction(function () use ($company, $data, $actor): CrmContractorTaxRegime {
            $regime = $company->taxRegime()->firstOrNew([]);

            $note = trim((string) ($data['note'] ?? ''));

            $regime->fill([
                'current_regime' => $data['current_regime'],
                'planned_regime' => $data['planned_regime'],
                // План всегда про следующий год: форма спрашивает именно о нём.
                'planned_year' => CrmContractorTaxRegime::targetYear(),
                'note' => $note === '' ? null : $note,
                'source' => CrmContractorTaxRegime::SOURCE_MANAGER,
            ]);

            // Поле не пришло — не трогаем: менеджер не должен стирать то, что клиент сказал сам.
            if (array_key_exists('vat_preference', $data)) {
                $regime->vat_preference = $data['vat_preference'];
            }

            return $this->stamp($company, $regime, $actor, CrmContractorTaxRegimeHistory::ACTION_SAVED);
        });
    }

    /**
     * Ответ из опроса на сайте — самого клиента или менеджера в режиме просмотра.
     *
     * «Уточню у бухгалтера» — законный ответ: чего не знают, то приходит пустым
     * и уже записанное не стирает. Без плана ответ неполный, и срок актуальности
     * не продлевается — иначе старый план выдавался бы за свежий.
     *
     * @param  array<string, mixed>  $data  current_regime, planned_regime?, vat_preference?
     * @param  User  $author  клиент или менеджер, открывший сайт от имени клиента
     * @param  string  $source  CrmContractorTaxRegime::SOURCE_*
     */
    public function saveSurveyAnswer(
        Company $company,
        array $data,
        User $author,
        string $source = CrmContractorTaxRegime::SOURCE_CLIENT,
    ): CrmContractorTaxRegime {
        return DB::transaction(function () use ($company, $data, $author, $source): CrmContractorTaxRegime {
            $regime = $company->taxRegime()->firstOrNew([]);

            $regime->current_regime = $data['current_regime'];
            $regime->source = $source;

            if (($data['vat_preference'] ?? null) !== null) {
                $regime->vat_preference = $data['vat_preference'];
            }

            if (($data['planned_regime'] ?? null) === null) {
                $regime->save();
                $this->journal($company, $regime, $author, CrmContractorTaxRegimeHistory::ACTION_SAVED);
                $company->setRelation('taxRegime', $regime);

                return $regime;
            }

            $regime->planned_regime = $data['planned_regime'];
            $regime->planned_year = CrmContractorTaxRegime::targetYear();

            return $this->stamp($company, $regime, $author, CrmContractorTaxRegimeHistory::ACTION_SAVED);
        });
    }

    /**
     * «Всё так же» — продлить актуальность ответа без изменений.
     */
    public function confirm(Company $company, User $actor): CrmContractorTaxRegime
    {
        $regime = $company->taxRegime;
        $year = CrmContractorTaxRegime::targetYear();

        if ($regime === null || ! $regime->isFilled()) {
            throw ValidationException::withMessages([
                'tax_regime' => "Подтверждать нечего: сначала укажите режим сейчас и план на {$year} год.",
            ]);
        }

        if ($regime->planned_year === null || $regime->planned_year < $year) {
            throw ValidationException::withMessages([
                'tax_regime' => "План указан на {$regime->planned_year} год, и этот год уже наступил. "
                    ."Укажите режим заново и план на {$year} год.",
            ]);
        }

        return DB::transaction(function () use ($company, $regime, $actor): CrmContractorTaxRegime {
            // Подтвердил менеджер — дальше за ответ отвечает он, даже если впервые его дал клиент.
            $regime->source = CrmContractorTaxRegime::SOURCE_MANAGER;

            return $this->stamp($company, $regime, $actor, CrmContractorTaxRegimeHistory::ACTION_CONFIRMED);
        });
    }

    /**
     * Налоговый режим юрлица для карточек и реестра.
     *
     * @return array<string, mixed>
     */
    public function payload(?CrmContractorTaxRegime $regime): array
    {
        $year = CrmContractorTaxRegime::targetYear();
        $freshness = CrmContractorTaxRegime::freshnessOf($regime);
        $current = $regime?->current_regime;
        $planned = $regime?->planned_regime;
        $preference = $regime?->vat_preference;
        $planIsCurrent = $regime?->planned_year !== null && $regime->planned_year >= $year;
        $shift = $planIsCurrent ? TaxRegimeShift::between($current, $planned) : null;

        // Год сменился: прошлогодний план — лучшая догадка о режиме сейчас.
        $suggestedCurrent = $current?->value;
        if (! $planIsCurrent && $planned !== null && $planned !== TaxRegime::UNDECIDED) {
            $suggestedCurrent = $planned->value;
        }

        return [
            'target_year' => $year,
            'current' => $this->regime($current),
            'planned' => $this->regime($planned),
            'planned_year' => $regime?->planned_year,
            'vat_preference' => $preference === null ? null : [
                'value' => $preference->value,
                'label' => $preference->label(),
                'short' => $preference->shortLabel(),
                'color' => $preference->color(),
            ],
            'source' => $regime === null ? null : [
                'value' => $regime->source,
                'label' => $regime->source === CrmContractorTaxRegime::SOURCE_CLIENT
                    ? 'ответ клиента на сайте'
                    : 'со слов менеджера',
            ],
            'note' => $regime?->note,
            'confirmed_at' => $regime?->confirmed_at?->format('d.m.Y'),
            'confirmed_by' => $regime?->confirmer?->name,
            'freshness' => [
                'value' => $freshness->value,
                'label' => $freshness->label(),
                'color' => $freshness->color(),
            ],
            'fresh_until' => $freshness === TaxRegimeFreshness::FRESH
                ? $regime?->freshUntil()?->format('d.m.Y')
                : null,
            'shift' => $shift === null ? null : [
                'value' => $shift->value,
                'label' => $shift->label(),
                'color' => $shift->color(),
                'risk' => $shift->isRisk(),
            ],
            'can_confirm' => $regime !== null && $regime->isFilled() && $planIsCurrent,
            'form' => [
                'current_regime' => $suggestedCurrent,
                'planned_regime' => $planIsCurrent ? $planned?->value : null,
                'vat_preference' => $preference?->value,
                'note' => $regime?->note,
            ],
        ];
    }

    /**
     * Варианты для формы.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'target_year' => CrmContractorTaxRegime::targetYear(),
            'current' => TaxRegime::currentOptions(),
            'planned' => TaxRegime::options(),
            'vat_preference' => VatPreference::options(),
            'confirm_days' => (int) config('crm_tax_regime.confirm_days'),
            'undecided_confirm_days' => (int) config('crm_tax_regime.undecided_confirm_days'),
        ];
    }

    private function stamp(Company $company, CrmContractorTaxRegime $regime, User $actor, string $action): CrmContractorTaxRegime
    {
        $regime->confirmed_at = now();
        $regime->confirmed_by = (int) $actor->getKey();
        $regime->save();

        $this->journal($company, $regime, $actor, $action);

        $company->setRelation('taxRegime', $regime);

        if ($company->user_id !== null) {
            // Ответ клиента закрывает задачу от имени её ответственного:
            // комментарий «закрыто» в CRM от клиента выглядел бы странно.
            $this->planner->closeSettled(
                (int) $company->user_id,
                $regime->source === CrmContractorTaxRegime::SOURCE_CLIENT ? null : $actor,
            );
        }

        return $regime;
    }

    private function journal(Company $company, CrmContractorTaxRegime $regime, User $actor, string $action): void
    {
        CrmContractorTaxRegimeHistory::create([
            'company_id' => $company->getKey(),
            'action' => $action,
            'current_regime' => $regime->current_regime,
            'planned_regime' => $regime->planned_regime,
            'planned_year' => $regime->planned_year,
            'vat_preference' => $regime->vat_preference,
            'source' => $regime->source,
            'note' => $regime->note,
            'user_id' => $actor->getKey(),
        ]);
    }

    /**
     * @return array{value: string, label: string, short: string}|null
     */
    private function regime(?TaxRegime $regime): ?array
    {
        return $regime === null ? null : [
            'value' => $regime->value,
            'label' => $regime->label(),
            'short' => $regime->shortLabel(),
        ];
    }
}
