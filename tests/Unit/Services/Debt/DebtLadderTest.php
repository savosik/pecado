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
        // Указание руководства 30.08.2026: предзаказы 10, заказы 14, стоп 32.
        $this->assertSame(10, $ladder->daysFor(DebtLevel::NO_PREORDERS));
        $this->assertSame(14, $ladder->daysFor(DebtLevel::NO_ORDERS));
        $this->assertSame(32, $ladder->daysFor(DebtLevel::HOLD));
        $this->assertSame(0.9, $ladder->holdShare());
        $this->assertSame(3, $ladder->staleAfterDays());
    }

    #[Test]
    public function steps_follow_the_agreed_days(): void
    {
        $ladder = DebtLadder::fromConfig();

        // 8–9-й день — только письмо и плашка, ограничений нет.
        $this->assertSame(DebtLevel::OVERDUE, $ladder->levelFor(50000, 8));
        $this->assertSame(DebtLevel::OVERDUE, $ladder->levelFor(50000, 9));
        $this->assertSame(DebtLevel::NO_PREORDERS, $ladder->levelFor(50000, 10));
        $this->assertSame(DebtLevel::NO_PREORDERS, $ladder->levelFor(50000, 13));
        $this->assertSame(DebtLevel::NO_ORDERS, $ladder->levelFor(50000, 14));
        // Полный стоп — только когда просрочка почти весь долг партнёра.
        $this->assertSame(DebtLevel::NO_ORDERS, $ladder->levelFor(50000, 32));
        $this->assertSame(DebtLevel::HOLD, $ladder->levelFor(50000, 32, partnerHold: true));
        $this->assertTrue($ladder->holdQualifies(50000, 50000, 32, 50000));
        $this->assertFalse($ladder->holdQualifies(50000, 50000, 31, 50000));
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
