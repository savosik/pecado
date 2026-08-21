<?php

namespace Tests\Feature\Cabinet;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Cabinet\CabinetFinance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CabinetFinancePilotTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['status' => UserStatus::ACTIVE->value]);
    }

    #[Test]
    public function payments_section_is_hidden_when_flag_off_and_not_in_pilot(): void
    {
        config(['cabinet.finance_enabled' => false, 'cabinet.finance_pilot_user_ids' => '']);

        $this->actingAs($this->client())
            ->get(route('cabinet.payments.index'))
            ->assertNotFound();
    }

    #[Test]
    public function pilot_user_sees_payments_section_while_flag_is_off(): void
    {
        $pilot = $this->client();
        $other = $this->client();

        config([
            'cabinet.finance_enabled' => false,
            'cabinet.finance_pilot_user_ids' => sprintf(' %d , 999999 ', $pilot->id),
        ]);

        $this->actingAs($pilot)->get(route('cabinet.payments.index'))->assertOk();
        $this->actingAs($other)->get(route('cabinet.payments.index'))->assertNotFound();
    }

    #[Test]
    public function global_flag_opens_section_for_everyone(): void
    {
        config(['cabinet.finance_enabled' => true, 'cabinet.finance_pilot_user_ids' => '']);

        $this->actingAs($this->client())
            ->get(route('cabinet.payments.index'))
            ->assertOk();
    }

    #[Test]
    public function pilot_list_parsing_ignores_garbage(): void
    {
        config(['cabinet.finance_pilot_user_ids' => ' 5, ,abc, 0, 7 ']);

        $this->assertSame([5, 7], CabinetFinance::pilotUserIds());

        config(['cabinet.finance_pilot_user_ids' => '']);
        $this->assertSame([], CabinetFinance::pilotUserIds());
    }
}
