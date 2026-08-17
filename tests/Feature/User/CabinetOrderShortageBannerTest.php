<?php

namespace Tests\Feature\User;

use App\Models\Order;
use App\Models\SubstitutionOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Баннер подборки замен на странице заказа в кабинете: пока автопоказ
 * (SHORTAGE_CLIENT_AUTO_ENABLED) выключен, клиент видит подборку только
 * после ручной отправки менеджером — не раньше, чем её проверил человек.
 */
class CabinetOrderShortageBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'substitutions.enabled' => true,
            'substitutions.client_auto_enabled' => false,
        ]);
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function makeOrderWithOffer(array $offerAttributes = []): array
    {
        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id]);

        SubstitutionOffer::factory()->create(array_merge([
            'order_id' => $order->id,
            'user_id' => $client->id,
            'expires_at' => now()->addDays(7),
        ], $offerAttributes));

        return [$client, $order];
    }

    #[Test]
    public function unsent_offer_is_hidden_while_the_auto_channel_is_off(): void
    {
        [$client, $order] = $this->makeOrderWithOffer(['sent_at' => null]);

        $this->actingAs($client)
            ->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('substitution', null));
    }

    #[Test]
    public function offer_sent_by_the_manager_stays_visible(): void
    {
        [$client, $order] = $this->makeOrderWithOffer(['sent_at' => now()->subHour()]);

        $this->actingAs($client)
            ->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('substitution.state', 'offered'));
    }

    #[Test]
    public function auto_channel_flag_restores_the_banner(): void
    {
        config(['substitutions.client_auto_enabled' => true]);

        [$client, $order] = $this->makeOrderWithOffer(['sent_at' => null]);

        $this->actingAs($client)
            ->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('substitution.state', 'offered'));
    }
}
