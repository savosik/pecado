<?php

namespace Tests\Unit\Services\Debt;

use App\Enums\DebtLevel;
use App\Services\Debt\DebtLadder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Боевые пороги лестницы долга: страж от тихого сдвига.
 *
 * Пороги меняются релизом и влияют на реальные блокировки, поэтому их значения
 * зафиксированы тестом — правка конфига без правки теста не проходит.
 */
class DebtLadderTest extends TestCase
{
    #[Test]
    public function production_thresholds_are_the_agreed_ones(): void
    {
        $ladder = DebtLadder::fromConfig();

        $this->assertSame(5000.0, $ladder->minOverdue());
        $this->assertSame(5, $ladder->graceBankDays());
        // Блокировка заказов — 14 дней (указание руководства 30.08.2026).
        $this->assertSame(14, $ladder->daysFor(DebtLevel::NO_ORDERS));
        // Предзаказы режутся тем же порогом: раньше некуда, льготный период
        // заканчивается на 8-й день.
        $this->assertSame(14, $ladder->daysFor(DebtLevel::NO_PREORDERS));
        $this->assertSame(60, $ladder->daysFor(DebtLevel::HOLD));
        $this->assertSame(0.9, $ladder->holdShare());
        $this->assertSame(3, $ladder->staleAfterDays());
    }

    #[Test]
    public function equal_thresholds_give_no_orders_not_no_preorders(): void
    {
        $ladder = DebtLadder::fromConfig();

        $this->assertSame(DebtLevel::OVERDUE, $ladder->levelFor(50000, 8));
        $this->assertSame(DebtLevel::OVERDUE, $ladder->levelFor(50000, 13));
        // На 14-й день закрываются и предзаказы, и заказы контрагента.
        $this->assertSame(DebtLevel::NO_ORDERS, $ladder->levelFor(50000, 14));
        $this->assertTrue($ladder->levelFor(50000, 14)->blocksPreorders());
        // Ниже отсечки ступени нет вовсе.
        $this->assertSame(DebtLevel::CLEAN, $ladder->levelFor(4999, 400));
    }

    #[Test]
    public function grace_period_counts_bank_days_only(): void
    {
        $ladder = DebtLadder::fromConfig();

        // Четверг 27.08.2026 минус 5 банковских дней — четверг 20.08.
        $this->assertSame('2026-08-20', $ladder->graceCutoff(CarbonImmutable::parse('2026-08-27'))->toDateString());
    }
}
