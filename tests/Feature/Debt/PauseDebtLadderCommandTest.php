<?php

namespace Tests\Feature\Debt;

use App\Models\DebtPause;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * debt:pause — разблокировка из консоли при выкатке: один раз, от имени менеджера.
 */
class PauseDebtLadderCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_partner_wide_pause_once(): void
    {
        $manager = User::factory()->create(['name' => 'Сухов']);
        $card = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $client = User::factory()->create(['personal_manager_id' => $card->id, 'erp_name' => 'Гевея ООО']);

        $this->artisan('debt:pause', ['user' => $client->id, '--days' => 45, '--if-never' => true, '--reason' => 'Договорённость'])
            ->expectsOutputToContain('Гевея ООО: разблокировка до')
            ->assertSuccessful();

        $pause = DebtPause::query()->where('user_id', $client->id)->sole();
        $this->assertNull($pause->company_id);
        $this->assertSame($manager->id, $pause->created_by);
        // Потолок РОП — 30 дней, даже если попросили 45.
        $this->assertSame(now()->addDays(30)->toDateString(), $pause->until->toDateString());

        $this->artisan('debt:pause', ['user' => $client->id, '--if-never' => true])
            ->expectsOutputToContain('уже ставилась')
            ->assertSuccessful();

        $this->assertSame(1, DebtPause::query()->where('user_id', $client->id)->count());
    }

    #[Test]
    public function fails_without_anyone_to_sign(): void
    {
        $client = User::factory()->create();

        $this->artisan('debt:pause', ['user' => $client->id])->assertFailed();
    }
}
