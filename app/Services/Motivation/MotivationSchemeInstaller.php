<?php

namespace App\Services\Motivation;

use App\Models\PayrollScheme;
use App\Models\User;
use App\Services\Payroll\PayrollCatalog;
use App\Services\Payroll\PayrollSchemeRepository;
use Carbon\CarbonInterface;

/**
 * Ввод схемы v2 — той, что вводит Положение о мотивации редакции 2.2.
 *
 * Схема заводится записью в payroll_schemes, а не подменой умолчаний конфига:
 * на умолчания v1 опирается контрольный тест действующей формулы, и он обязан
 * оставаться зелёным. Прежняя версия не изменяется — расчёт прошлого месяца
 * читается по схеме, действовавшей тогда.
 *
 * Значения параметров берутся из действующего приказа
 * ({@see \App\Models\Motivation\MotivationParameterOrder}), а при его отсутствии —
 * из умолчаний Приложения № 1 в config/motivation.php. Дальше они живут в базе
 * и меняются руководителем без выкладки кода.
 */
class MotivationSchemeInstaller
{
    public function __construct(
        private readonly PayrollSchemeRepository $schemes,
        private readonly PayrollCatalog $catalog,
    ) {}

    /**
     * Завести схему v2 с указанного месяца.
     *
     * @param  array<string, mixed>|null  $parameters  значения приказа; null — умолчания Приложения № 1
     */
    public function install(CarbonInterface $effectiveFrom, ?User $author = null, ?array $parameters = null): PayrollScheme
    {
        $values = $parameters ?? (array) config('motivation.default_parameters', []);
        $definition = (array) config('motivation.scheme_v2', []);

        $components = [];
        foreach ((array) ($definition['components'] ?? []) as $key) {
            $key = (string) $key;

            if (! $this->catalog->exists($key)) {
                continue;
            }

            $components[] = [
                'key' => $key,
                'enabled' => true,
                'defaults' => self::componentDefaults($key, $values),
            ];
        }

        return $this->schemes->createVersion(
            components: $components,
            effectiveFrom: $effectiveFrom,
            author: $author,
            comment: 'Положение о мотивации и об оплате труда, редакция 2.2',
            title: (string) ($definition['title'] ?? 'Отдел продаж — Положение 2.2'),
        );
    }

    /**
     * Раскладка параметров приказа по компонентам схемы.
     *
     * Здесь и только здесь плоский набор Приложения № 1 превращается в параметры
     * компонентов: приказ — документ на отдел, компонент — формула, и соответствие
     * между ними должно быть записано в одном месте, а не угадываться по именам.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function componentDefaults(string $key, array $values): array
    {
        return match ($key) {
            'salary' => ['amount' => $values['salary'] ?? 0],
            'motivation_channels_allowance' => ['amount' => $values['channels_allowance'] ?? 0],
            'motivation_substitution' => ['per_day' => $values['substitution_per_day'] ?? 0],
            'motivation_variable' => [
                'payment_threshold' => $values['payment_threshold'] ?? 0.6,
                'rate_p1' => $values['rate_p1'] ?? 0,
                'rate_p2' => $values['rate_p2'] ?? 0,
                'rate_p3' => $values['rate_p3'] ?? 0,
                'rate_k1_per_day' => $values['rate_k1_per_day'] ?? 0,
                'cap' => $values['variable_cap'] ?? 0,
            ],
            // База гарантии — величина по работнику, она фиксируется персональным
            // отклонением на дату введения Положения, а не задаётся схемой на всех.
            'motivation_guarantee' => [
                'share' => $values['transition_guarantee_share'] ?? 0,
                'base' => 0,
            ],
            default => [],
        };
    }
}
