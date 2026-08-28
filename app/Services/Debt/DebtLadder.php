<?php

namespace App\Services\Debt;

use App\Enums\DebtLevel;
use Carbon\CarbonImmutable;

/**
 * Пороги лестницы долга — чистая арифметика без базы.
 *
 * Всё, что можно объяснить одной фразой, живёт здесь: отсечка, льготный
 * период в банковских днях, три возрастных порога и доля просрочки в долге
 * для стоп-отгрузки. Других сигналов нет намеренно.
 */
final class DebtLadder
{
    /**
     * @param  array<string, mixed>  $config  содержимое config('debt')
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('debt', []));
    }

    public function minOverdue(): float
    {
        return (float) ($this->config['min_overdue'] ?? 5000);
    }

    public function graceBankDays(): int
    {
        return max(0, (int) ($this->config['grace_bank_days'] ?? 5));
    }

    public function staleAfterDays(): int
    {
        return max(0, (int) ($this->config['stale_after_days'] ?? 3));
    }

    public function dueSoonDays(): int
    {
        return max(0, (int) ($this->config['due_soon_days'] ?? 3));
    }

    public function holdShare(): float
    {
        return (float) ($this->config['hold_share'] ?? 0.9);
    }

    public function daysFor(DebtLevel $level): int
    {
        return match ($level) {
            DebtLevel::NO_PREORDERS => (int) ($this->config['no_preorders_days'] ?? 14),
            DebtLevel::NO_ORDERS => (int) ($this->config['no_orders_days'] ?? 30),
            DebtLevel::HOLD => (int) ($this->config['hold_days'] ?? 60),
            default => 0,
        };
    }

    /**
     * Строка считается просроченной, когда её срок раньше этой даты:
     * сегодня минус льготный период в банковских днях (пн–пт).
     */
    public function graceCutoff(CarbonImmutable $today): CarbonImmutable
    {
        $date = $today;
        $left = $this->graceBankDays();

        while ($left > 0) {
            $date = $date->subDay();

            if ($date->isWeekday()) {
                $left--;
            }
        }

        return $date;
    }

    /**
     * Значимая ли просрочка — ниже отсечки клиент чист.
     */
    public function isSignificant(float $overdue): bool
    {
        return $overdue >= $this->minOverdue();
    }

    /**
     * Ступень контрагента по значимой просрочке и возрасту самой старой строки.
     * `$partnerHold` — партнёр в целом заслужил стоп-отгрузку (см. holdQualifies).
     */
    public function levelFor(float $overdue, int $ageDays, bool $partnerHold = false): DebtLevel
    {
        if (! $this->isSignificant($overdue)) {
            return DebtLevel::CLEAN;
        }

        if ($partnerHold && $ageDays >= $this->daysFor(DebtLevel::HOLD)) {
            return DebtLevel::HOLD;
        }

        if ($ageDays >= $this->daysFor(DebtLevel::NO_ORDERS)) {
            return DebtLevel::NO_ORDERS;
        }

        if ($ageDays >= $this->daysFor(DebtLevel::NO_PREORDERS)) {
            return DebtLevel::NO_PREORDERS;
        }

        return DebtLevel::OVERDUE;
    }

    /**
     * «Просрочка равна всему долгу и давняя»: стоп-отгрузка для партнёра.
     * У живого клиента свежие отгрузки всегда дают непросроченный долг,
     * поэтому сюда попадают те, кто перестал покупать.
     */
    public function holdQualifies(float $partnerOverdueTotal, float $partnerDebt, int $partnerAgeDays, float $partnerOverdue): bool
    {
        if (! $this->isSignificant($partnerOverdue)) {
            return false;
        }

        if ($partnerAgeDays < $this->daysFor(DebtLevel::HOLD)) {
            return false;
        }

        if ($partnerDebt <= 0.0) {
            return false;
        }

        return ($partnerOverdueTotal / $partnerDebt) >= $this->holdShare();
    }

    /**
     * Ужесточение — не больше чем на одну ступень за пересчёт.
     */
    public function stepDown(DebtLevel $previous, DebtLevel $measured): DebtLevel
    {
        if (! $measured->isWorseThan($previous)) {
            return $measured;
        }

        return DebtLevel::fromRank(min($measured->rank(), $previous->rank() + 1));
    }
}
