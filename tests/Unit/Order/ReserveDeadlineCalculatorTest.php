<?php

namespace Tests\Unit\Order;

use App\Services\Order\ReserveDeadlineCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * res-12 (v16.12.1, топик №11 Agent Hub): срок резерва в рабочих днях клиента.
 * Примеры — ровно те, что согласованы с 1С в топике и записаны в тест-план Р-8.
 */
class ReserveDeadlineCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Календарь задан явно: тест не должен зависеть от того, как заполнен конфиг.
        // 2025 — фрагмент реального календаря с рабочей субботой 01.11 и переносом на 03.11.
        config([
            'production_calendar.holidays' => [
                2025 => ['2025-11-03', '2025-11-04', '2025-12-31'],
                2026 => [
                    '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05',
                    '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09',
                    '2026-05-01', '2026-05-11', '2026-11-04', '2026-12-31',
                ],
                2027 => [
                    '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04',
                    '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08',
                ],
            ],
            'production_calendar.working_weekends' => [2025 => ['2025-11-01'], 2026 => [], 2027 => []],
        ]);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function deadlines(): array
    {
        return [
            'чт → пт' => ['2026-10-01 17:00', 24, '2026-10-02 17:00'],
            'пт → пн, тот же час' => ['2026-10-02 17:00', 24, '2026-10-05 17:00'],
            'сб → пн' => ['2026-10-03 10:00', 24, '2026-10-05 10:00'],
            'вс → пн' => ['2026-10-04 22:00', 24, '2026-10-05 22:00'],
            'рабочая суббота по переносу' => ['2025-10-31 17:00', 24, '2025-11-01 17:00'],
            'из рабочей субботы через перенос' => ['2025-11-01 17:00', 24, '2025-11-05 17:00'],
            '4 ноября' => ['2026-11-03 17:00', 24, '2026-11-05 17:00'],
            'майские' => ['2026-04-30 17:00', 24, '2026-05-04 17:00'],
            'окно 120 ч' => ['2026-10-01 17:00', 120, '2026-10-08 17:00'],
            'окно 168 ч срезано по потолку на рабочий день' => ['2026-10-02 17:00', 168, '2026-10-12 17:00'],
            'последний резерв года' => ['2026-12-29 17:00', 24, '2026-12-30 17:00'],
            'воскресенье в конце каникул — каникулы не задеты' => ['2027-01-10 20:00', 24, '2027-01-11 20:00'],
        ];
    }

    #[Test]
    #[DataProvider('deadlines')]
    public function deadline_skips_client_non_working_days(string $created, int $window, string $expected): void
    {
        $createdAt = CarbonImmutable::parse($created);
        $deadline = $this->calculator()->deadline($createdAt, $window, 240);

        $this->assertNotNull($deadline);
        $this->assertSame($expected, $deadline->format('Y-m-d H:i'));
        $this->assertLessThanOrEqual(240 * 3600, $createdAt->diffInSeconds($deadline));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function newYear(): array
    {
        return [
            'последний рабочий день года' => ['2026-12-30 17:00'],
            'внутри каникул' => ['2027-01-04 12:00'],
            'в прошлом году — 30.12.2025' => ['2025-12-30 17:00'],
        ];
    }

    #[Test]
    #[DataProvider('newYear')]
    public function reserve_is_not_offered_across_new_year_holidays(string $created): void
    {
        $createdAt = CarbonImmutable::parse($created);

        $this->assertNull($this->calculator()->deadline($createdAt, 24, 240));
        $this->assertSame(
            ReserveDeadlineCalculator::REASON_NEW_YEAR,
            $this->calculator()->blockReason($createdAt, 24, 240),
        );
    }

    #[Test]
    public function exact_limit_is_not_cut(): void
    {
        // 72 ч от вт 13:00 без праздников: ср, чт, пт → пт 13:00 = ровно 72 ч = предел
        $deadline = $this->calculator()->deadline(CarbonImmutable::parse('2026-10-06 13:00'), 72, 72);

        $this->assertSame('2026-10-09 13:00', $deadline?->format('Y-m-d H:i'));
    }

    #[Test]
    public function no_working_day_under_limit_means_no_reserve(): void
    {
        // Предел 24 ч от пятницы: под потолком одна суббота — рабочего дня нет
        $createdAt = CarbonImmutable::parse('2026-10-02 17:00');

        $this->assertNull($this->calculator()->deadline($createdAt, 24, 24));
        $this->assertSame(
            ReserveDeadlineCalculator::REASON_NO_WORKING_DAY,
            $this->calculator()->blockReason($createdAt, 24, 24),
        );
    }

    private function calculator(): ReserveDeadlineCalculator
    {
        return app(ReserveDeadlineCalculator::class);
    }
}
