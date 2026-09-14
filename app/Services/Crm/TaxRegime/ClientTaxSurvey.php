<?php

namespace App\Services\Crm\TaxRegime;

use App\Enums\Crm\TaxRegime;
use App\Enums\Crm\VatPreference;
use App\Enums\UserKind;
use App\Models\Company;
use App\Models\CrmContractorTaxRegime;
use App\Models\CrmTaxSurveyPrompt;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Короткий опрос клиента на сайте: как юрлицо работает с НДС сейчас, что
 * планирует на следующий год и насколько ему важен НДС в товаре.
 *
 * Спрашиваем только о покупающих юрлицах без актуального ответа — тех же, по
 * которым менеджеру ставится задача. Ответ клиента закрывает её сам. Опрос
 * ненавязчив: приглашение можно отложить, после нескольких «Не сейчас» оно
 * больше не показывается, остаётся лишь ярлычок на краю экрана.
 */
class ClientTaxSurvey
{
    /**
     * Метка «спрашивать не о чем» в кеше: `Cache::remember` не хранит null,
     * и без неё большинство клиентов, уже ответивших, пересчитывали бы опрос
     * на каждой странице каталога.
     */
    private const NOTHING_TO_ASK = ['nothing_to_ask' => true];

    public function __construct(
        private readonly TaxRegimeQuery $query,
        private readonly ContractorTaxRegimeService $regimes,
    ) {}

    public static function cacheKey(int $userId): string
    {
        return "crm:tax-survey:{$userId}";
    }

    /**
     * Опрос для общего пропса Inertia; null — показывать нечего.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(?User $user): ?array
    {
        if ($user === null || ! config('crm_tax_regime.client_survey.enabled') || ! $this->isClient($user)) {
            return null;
        }

        $payload = Cache::remember(
            self::cacheKey((int) $user->getKey()),
            now()->addMinutes(10),
            fn (): array => $this->build($user),
        );

        return $payload === self::NOTHING_TO_ASK ? null : $payload;
    }

    /**
     * Ответы опроса.
     *
     * Менеджер, открывший сайт от имени клиента, проходит тот же опрос во время
     * звонка — ответ записывается как его, а не как слова клиента.
     *
     * @param  list<array<string, mixed>>  $answers
     * @param  User|null  $manager  менеджер в режиме просмотра; null — отвечает сам клиент
     */
    public function save(User $client, array $answers, ?User $manager = null): void
    {
        DB::transaction(function () use ($client, $answers, $manager): void {
            foreach ($answers as $answer) {
                $company = Company::query()
                    ->where('companies.user_id', $client->getKey())
                    ->findOrFail((int) $answer['company_id']);

                $this->regimes->saveSurveyAnswer(
                    $company,
                    $answer,
                    $manager ?? $client,
                    $manager === null ? CrmContractorTaxRegime::SOURCE_CLIENT : CrmContractorTaxRegime::SOURCE_MANAGER,
                );
            }

            // Клиент ответил сам — счётчик отказов обнуляется: когда ответ
            // устареет, пригласить снова будет честно.
            if ($manager === null) {
                CrmTaxSurveyPrompt::query()
                    ->where('user_id', $client->getKey())
                    ->update(['snooze_count' => 0, 'snoozed_until' => null]);
            }
        });

        Cache::forget(self::cacheKey((int) $client->getKey()));
    }

    public function snooze(User $client): void
    {
        $prompt = CrmTaxSurveyPrompt::query()->firstOrNew(['user_id' => $client->getKey()]);
        $prompt->snooze_count++;
        $prompt->snoozed_until = now()->addDays((int) config('crm_tax_regime.client_survey.snooze_days'));
        $prompt->save();

        Cache::forget(self::cacheKey((int) $client->getKey()));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(User $user): array
    {
        $year = CrmContractorTaxRegime::targetYear();

        $contractors = $this->query->pending((int) $user->getKey())
            ->with('taxRegime')
            ->orderByDesc('companies.is_default')
            ->orderBy('companies.name')
            ->get();

        if ($contractors->isEmpty()) {
            return self::NOTHING_TO_ASK;
        }

        $prompt = CrmTaxSurveyPrompt::query()->where('user_id', $user->getKey())->first();

        return [
            'target_year' => $year,
            'invite' => $prompt === null || $prompt->allowsInvite(),
            'contractors' => $contractors->map(fn (Company $company): array => [
                'id' => (int) $company->getKey(),
                'name' => (string) ($company->name ?: $company->legal_name ?: 'Ваша компания'),
                'tax_id' => $company->tax_id,
                // Что уже известно — отмечено в опросе заранее: подтвердить быстрее, чем выбрать.
                'current_regime' => $company->taxRegime?->current_regime?->value,
                'planned_regime' => $company->taxRegime?->planned_year !== null
                    && $company->taxRegime->planned_year >= $year
                        ? $company->taxRegime->planned_regime?->value
                        : null,
                'vat_preference' => $company->taxRegime?->vat_preference?->value,
            ])->values()->all(),
            'options' => [
                'current' => TaxRegime::clientOptions(false),
                'planned' => TaxRegime::clientOptions(true),
                'vat_preference' => VatPreference::options(),
            ],
        ];
    }

    /**
     * Опрос — только партнёрам: сотрудникам (у них роли) и служебным учёткам его не показываем.
     */
    private function isClient(User $user): bool
    {
        return $user->user_kind === UserKind::CLIENT && ! $user->isStaff();
    }
}
