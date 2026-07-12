<?php

namespace Tests\Feature;

use App\Models\DeliveryAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryAddressControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_toggle_default_sets_address_as_default(): void
    {
        $address = DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/cabinet/delivery-addresses/{$address->id}/toggle-default");

        $response->assertOk();
        $response->assertJson(['is_default' => true, 'address_id' => $address->id]);
        $this->assertTrue($address->fresh()->is_default);
    }

    public function test_toggle_default_keeps_only_one_default(): void
    {
        $first = DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => true,
        ]);
        $second = DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => false,
        ]);

        $this->actingAs($this->user)
            ->post("/cabinet/delivery-addresses/{$second->id}/toggle-default")
            ->assertOk();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_toggle_default_can_unset(): void
    {
        $address = DeliveryAddress::factory()->create([
            'user_id' => $this->user->id,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->post("/cabinet/delivery-addresses/{$address->id}/toggle-default");

        $response->assertJson(['is_default' => false]);
        $this->assertFalse($address->fresh()->is_default);
    }

    public function test_toggle_default_not_found_for_other_user(): void
    {
        // Глобальный DeliveryAddressScope ограничивает выборку своими адресами,
        // поэтому route model binding не найдёт чужой адрес → 404.
        $other = User::factory()->create();
        $address = DeliveryAddress::factory()->create([
            'user_id' => $other->id,
        ]);

        $this->actingAs($this->user)
            ->post("/cabinet/delivery-addresses/{$address->id}/toggle-default")
            ->assertNotFound();
    }
}
