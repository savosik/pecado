<?php

namespace Tests\Feature\Console;

use App\Models\SettlementEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Чистка дублирующих строк начального сальдо (v16.3.0).
 */
class DropOpeningBalancesTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $type, float $amount): SettlementEntry
    {
        return SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => $type,
            'amount' => $amount,
        ]);
    }

    #[Test]
    public function удаляет_только_строки_начального_сальдо(): void
    {
        $this->entry(SettlementEntry::TYPE_OPENING_BALANCE, -50000);
        $keep = $this->entry(SettlementEntry::TYPE_SHIPMENT, -5000);

        $this->artisan('settlements:drop-opening-balances --force')->assertExitCode(0);

        $this->assertSame(0, SettlementEntry::query()
            ->where('type', SettlementEntry::TYPE_OPENING_BALANCE)->count());
        $this->assertDatabaseHas('settlement_entries', ['id' => $keep->id]);
    }

    /**
     * Идемпотентность важнее обычного: команда попадёт в деплой, а деплой
     * повторяется. Второй запуск обязан пройти без ошибки.
     */
    #[Test]
    public function повторный_запуск_не_падает(): void
    {
        $this->entry(SettlementEntry::TYPE_SHIPMENT, -5000);

        $this->artisan('settlements:drop-opening-balances --force')->assertExitCode(0);
        $this->artisan('settlements:drop-opening-balances --force')->assertExitCode(0);

        $this->assertSame(1, SettlementEntry::query()->count());
    }

    #[Test]
    public function пробный_запуск_ничего_не_удаляет(): void
    {
        $this->entry(SettlementEntry::TYPE_OPENING_BALANCE, -50000);

        $this->artisan('settlements:drop-opening-balances --dry-run')->assertExitCode(0);

        $this->assertSame(1, SettlementEntry::query()->count());
    }
}
