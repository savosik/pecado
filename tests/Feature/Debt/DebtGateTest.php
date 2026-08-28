<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Exceptions\DebtRestrictionException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Company;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\User;
use App\Services\Debt\DebtGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Гейт чекаута: ступень × тип позиции × контрагент, разблокировка, тень.
 */
class DebtGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['debt.enabled' => true, 'debt.mode' => 'live', 'debt.live_actions' => 'gate']);
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->user->id, 'name' => 'ООО Ромашка']);
    }

    #[Test]
    public function clean_and_overdue_levels_do_not_block(): void
    {
        $this->state(null, DebtLevel::OVERDUE);
        $this->state($this->company, DebtLevel::OVERDUE);

        $this->gate()->check($this->user, $this->company, $this->cart(['preorder', 'instock']));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function no_preorders_rejects_cart_with_preorder_lines_only(): void
    {
        $this->state(null, DebtLevel::NO_PREORDERS);
        $this->state($this->company, DebtLevel::NO_PREORDERS);

        $this->gate()->check($this->user, $this->company, $this->cart(['instock']));

        $this->expectException(DebtRestrictionException::class);
        $this->expectExceptionMessage('Предзаказы приостановлены');
        $this->gate()->check($this->user, $this->company, $this->cart(['instock', 'preorder']));
    }

    #[Test]
    public function no_orders_blocks_only_that_contractor(): void
    {
        $other = Company::factory()->create(['user_id' => $this->user->id, 'name' => 'ИП Иванов']);
        $this->state(null, DebtLevel::NO_ORDERS);
        $this->state($this->company, DebtLevel::NO_ORDERS);
        $this->state($other, DebtLevel::CLEAN);

        $this->gate()->check($this->user, $other, $this->cart(['instock']));

        try {
            $this->gate()->check($this->user, $this->company, $this->cart(['instock']));
            $this->fail('Ожидался отказ по контрагенту с закрытыми заказами');
        } catch (DebtRestrictionException $exception) {
            $this->assertSame(DebtLevel::NO_ORDERS, $exception->level);
            $this->assertFalse($exception->blocksAllOrders);
            $this->assertStringContainsString('ООО Ромашка', $exception->getMessage());
        }
    }

    #[Test]
    public function hold_blocks_every_contractor_of_the_partner(): void
    {
        $other = Company::factory()->create(['user_id' => $this->user->id]);
        $this->state(null, DebtLevel::HOLD);
        $this->state($this->company, DebtLevel::HOLD);

        try {
            $this->gate()->check($this->user, $other, $this->cart(['instock']));
            $this->fail('Ожидался стоп всех заказов партнёра');
        } catch (DebtRestrictionException $exception) {
            $this->assertTrue($exception->blocksAllOrders);
            $this->assertSame(DebtLevel::HOLD, $exception->level);
        }
    }

    #[Test]
    public function active_pause_lifts_the_gate(): void
    {
        $this->state(null, DebtLevel::HOLD);
        $this->state($this->company, DebtLevel::HOLD);
        DebtPause::create([
            'user_id' => $this->user->id,
            'until' => now()->addDays(3)->toDateString(),
            'reason' => 'Обещал оплатить',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->gate()->check($this->user, $this->company, $this->cart(['preorder']));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function shadow_rows_and_disabled_gate_action_never_block(): void
    {
        $this->state(null, DebtLevel::HOLD, dryRun: true);
        $this->gate()->check($this->user, $this->company, $this->cart(['instock']));

        $this->state(null, DebtLevel::HOLD);
        config(['debt.live_actions' => 'mail']);
        $this->gate()->check($this->user, $this->company, $this->cart(['instock']));
        $this->addToAssertionCount(2);
    }

    private function state(?Company $company, DebtLevel $level, bool $dryRun = false): void
    {
        DebtState::query()->updateOrCreate(
            ['user_id' => $this->user->id, 'company_id' => $company?->id],
            [
                'level' => $level,
                'since' => now()->toDateString(),
                'overdue_amount' => $level === DebtLevel::CLEAN ? 0 : 126098,
                'overdue_total' => $level === DebtLevel::CLEAN ? 0 : 126098,
                'age_days' => 54,
                'lines_count' => 1,
                'reason' => 'тест',
                'dry_run' => $dryRun,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * @param  list<string>  $itemTypes
     */
    private function cart(array $itemTypes): Cart
    {
        $cart = Cart::factory()->create(['user_id' => $this->user->id]);

        foreach ($itemTypes as $type) {
            CartItem::factory()->create(['cart_id' => $cart->id, 'item_type' => $type]);
        }

        return $cart->fresh(['items']);
    }

    private function gate(): DebtGate
    {
        return app(DebtGate::class);
    }
}
