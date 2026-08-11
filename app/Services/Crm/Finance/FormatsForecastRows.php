<?php

namespace App\Services\Crm\Finance;

use App\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Общая часть двух реализаций счётного ядра: форматирование строк, корзины
 * времени и сведение валют (v16.0.0).
 *
 * Вынесено в трейт, а не в базовый класс, потому что делится ровно то, что
 * не зависит от источника данных. Запросы у реализаций разные до последней
 * колонки, а вот подпись остатка, метка месяца и корзины давности обязаны
 * совпадать: иначе при переключении флага у менеджера поедут не только цифры,
 * но и то, как они выглядят, — и отличить одно от другого станет нельзя.
 */
trait FormatsForecastRows
{
    /** Корзины просрочки в днях: до какого дня включительно попадает строка. */
    public const AGING_BUCKETS = [
        ['key' => '1_7', 'label' => '1–7 дней', 'to' => 7],
        ['key' => '8_14', 'label' => '8–14 дней', 'to' => 14],
        ['key' => '15_30', 'label' => '15–30 дней', 'to' => 30],
        ['key' => '31_60', 'label' => '31–60 дней', 'to' => 60],
        ['key' => '60_plus', 'label' => 'более 60 дней', 'to' => null],
    ];

    /**
     * Курсы валют к рублю, code => exchange_rate. Читаются один раз на запрос:
     * строк плана тысячи, а справочник валют — единицы.
     *
     * @var array<string, float>|null
     */
    private ?array $rates = null;

    /**
     * Свод плана и факта по корзинам времени — то, из чего строится график
     * на пульте и блок «Ожидается по неделям» в выгрузке.
     *
     * @param  Collection<string, float>  $plan  день => рубли
     * @param  Collection<string, array{amount: float, count: int}>  $facts
     * @return list<array{key: string, label: string, from: string, to: string, plan: float, fact: float}>
     */
    public function buckets(Collection $plan, Collection $facts, FinanceFilters $filters): array
    {
        $buckets = [];
        $cursor = $filters->dateFrom;

        while ($cursor->lessThanOrEqualTo($filters->dateTo)) {
            $to = match ($filters->granularity) {
                'day' => $cursor,
                'month' => $cursor->endOfMonth(),
                default => $cursor->addDays(6),
            };

            if ($to->greaterThan($filters->dateTo)) {
                $to = $filters->dateTo;
            }

            $buckets[] = [
                'key' => $cursor->toDateString(),
                'label' => $this->bucketLabel($cursor, $to, $filters->granularity),
                'from' => $cursor->toDateString(),
                'to' => $to->toDateString(),
                'plan' => round($this->sumRange($plan, $cursor, $to, static fn (float $v): float => $v), 2),
                'fact' => round($this->sumRange($facts, $cursor, $to, static fn (array $v): float => $v['amount']), 2),
            ];

            $cursor = match ($filters->granularity) {
                'day' => $cursor->addDay(),
                'month' => $cursor->addMonth()->startOfMonth(),
                default => $cursor->addDays(7),
            };
        }

        return $buckets;
    }

    /**
     * Строка плана в виде, пригодном и для таблицы, и для выгрузки.
     *
     * @return array<string, mixed>
     */
    public function row(object $raw, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $unpaid = $this->unpaidOf($raw);
        $dueDate = $raw->due_date !== null ? CarbonImmutable::parse($raw->due_date) : null;
        $isOverdue = $dueDate !== null && $dueDate->lessThan($today);
        $key = ($dueDate !== null ? 'sch-' : 'shp-').$raw->id;

        return [
            // Ключ таблицы: строки графика и реализации без графика живут в одном
            // списке, и голый id столкнулся бы между двумя источниками. Дублируется
            // в `id`, потому что DataTable ключует строки именно по нему.
            'key' => $key,
            'id' => $key,
            'source' => $dueDate !== null ? 'schedule' : 'no_schedule',
            'due_date' => $dueDate?->toDateString(),
            'due_date_label' => $dueDate?->format('d.m.Y') ?? 'Срок не определён',
            'amount' => round((float) $raw->amount, 2),
            'paid_amount' => round((float) $raw->paid_amount, 2),
            'unpaid_amount' => $unpaid,
            'unpaid_rub' => round($this->toRub($unpaid, $raw->currency_code), 2),
            'unpaid_label' => $this->unpaidLabel($unpaid, $raw->currency_code),
            'currency_code' => $raw->currency_code ?: 'RUB',
            'is_overdue' => $isOverdue,
            'days_overdue' => $isOverdue ? (int) $dueDate->diffInDays($today) : 0,
            'days_left' => $dueDate !== null && ! $isOverdue ? (int) $today->diffInDays($dueDate) : null,
            'stage_name' => $raw->stage_name,
            'organization_name' => $raw->organization_name,
            'manager_name' => $raw->manager_name,
            'client' => [
                'id' => (int) $raw->user_id,
                'name' => $raw->client_erp_name ?: $raw->client_name,
                'url' => route('crm.clients.show', $raw->user_id),
            ],
            'shipment' => [
                'id' => (int) $raw->shipment_id,
                'number' => $raw->shipment_erp_number ?: $raw->shipment_number ?: ('#'.$raw->shipment_id),
                'date' => $raw->shipment_date !== null ? CarbonImmutable::parse($raw->shipment_date)->format('d.m.Y') : null,
                'invoice_number' => $raw->invoice_number_display,
                'total' => round((float) $raw->shipment_total, 2),
                'url' => route('crm.shipments.show', $raw->shipment_id),
            ],
        ];
    }

    /**
     * Остаток к оплате по строке. Переплата остатка не создаёт — отсюда max(0, …).
     */
    public function unpaidOf(object $row): float
    {
        $prepaid = (float) ($row->prepaid_amount ?? 0);

        return round(max(0.0, (float) $row->amount - (float) $row->paid_amount - $prepaid), 2);
    }

    /**
     * Сумма в рублях. Неизвестный код валюты трактуем как рубли: потерять строку
     * из отчёта хуже, чем показать её без пересчёта.
     */
    public function toRub(float $amount, ?string $currencyCode): float
    {
        if ($currencyCode === null || $currencyCode === '' || $currencyCode === 'RUB') {
            return $amount;
        }

        $rate = $this->rates()[$currencyCode] ?? null;

        return $rate !== null && $rate > 0 ? round($amount * $rate, 2) : $amount;
    }

    /**
     * Подпись остатка. Суммы форматируются на сервере, как и даты.
     *
     * Валютный документ показывается в рублях с исходной суммой в скобках: в отчёте
     * складываются рубли, и менеджер должен видеть, откуда взялось рублёвое число.
     */
    private function unpaidLabel(float $unpaid, ?string $currencyCode): string
    {
        $rub = $this->money($this->toRub($unpaid, $currencyCode), 'RUB');

        if ($currencyCode === null || $currencyCode === '' || $currencyCode === 'RUB') {
            return $rub;
        }

        return $rub.' ('.$this->money($unpaid, $currencyCode).')';
    }

    private function money(float $amount, ?string $currencyCode): string
    {
        $symbol = match ($currencyCode) {
            'RUB', null => '₽',
            'KZT' => '₸',
            'BYN' => 'Br',
            default => $currencyCode,
        };

        return number_format($amount, 2, ',', ' ').' '.$symbol;
    }

    /**
     * @param  Collection<string, mixed>  $values
     * @param  callable(mixed): float  $extract
     */
    private function sumRange(Collection $values, CarbonImmutable $from, CarbonImmutable $to, callable $extract): float
    {
        $total = 0.0;

        foreach ($values as $day => $value) {
            if ($day >= $from->toDateString() && $day <= $to->toDateString()) {
                $total += $extract($value);
            }
        }

        return $total;
    }

    private function agingKey(int $days): string
    {
        foreach (self::AGING_BUCKETS as $bucket) {
            if ($bucket['to'] === null || $days <= $bucket['to']) {
                return $bucket['key'];
            }
        }

        return self::AGING_BUCKETS[array_key_last(self::AGING_BUCKETS)]['key'];
    }

    private function bucketLabel(CarbonImmutable $from, CarbonImmutable $to, string $granularity): string
    {
        return match ($granularity) {
            'day' => $from->format('d.m.Y'),
            'month' => $this->monthLabel($from),
            default => $from->format('d.m').' — '.$to->format('d.m'),
        };
    }

    /**
     * «Август 2026». Carbon::translatedFormat зависит от локали приложения,
     * а месяцы нужны по-русски в любом окружении.
     */
    private function monthLabel(CarbonImmutable $month): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        return $months[(int) $month->format('n')].' '.$month->format('Y');
    }

    /**
     * @return array<string, float>
     */
    private function rates(): array
    {
        return $this->rates ??= Currency::query()
            ->pluck('exchange_rate', 'code')
            ->map(fn ($rate): float => (float) $rate)
            ->all();
    }
}
