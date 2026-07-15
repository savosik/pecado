<?php

namespace Tests\Feature\Subscriptions;

use App\Models\EntitySubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CRUD универсальных подписок кабинета: добавление/список/удаление email,
 * валидация, дедупликация, проверка владельца и публичная отписка.
 */
class SubscriptionCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function user_can_subscribe_email_to_section(): void
    {
        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/orders', ['email' => 'Buh@Example.RU'])
            ->assertCreated()
            ->assertJsonPath('data.destination', 'buh@example.ru')
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('entity_subscriptions', [
            'user_id' => $this->user->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'buh@example.ru',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function invalid_email_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/orders', ['email' => 'не-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    #[Test]
    public function unknown_section_returns_404(): void
    {
        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/unknown-section', ['email' => 'a@b.ru'])
            ->assertNotFound();
    }

    #[Test]
    public function duplicate_email_does_not_create_second_row(): void
    {
        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/orders', ['email' => 'dup@example.ru'])
            ->assertCreated();

        // Повторное добавление уже активного адреса — 200, без новой строки.
        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/orders', ['email' => 'dup@example.ru'])
            ->assertOk();

        $this->assertDatabaseCount('entity_subscriptions', 1);
    }

    #[Test]
    public function index_excludes_inactive_subscriptions_and_exposes_max(): void
    {
        EntitySubscription::create(['user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'active@example.ru', 'is_active' => true]);
        EntitySubscription::create(['user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'off@example.ru', 'is_active' => false]);

        $this->actingAs($this->user)
            ->getJson('/cabinet/subscriptions/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.destination', 'active@example.ru')
            ->assertJsonPath('max', 5);
    }

    #[Test]
    public function store_rejects_when_limit_of_five_reached(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $p) {
            EntitySubscription::create(['user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => "$p@example.ru", 'is_active' => true]);
        }

        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/orders', ['email' => 'sixth@example.ru'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');

        $this->assertDatabaseMissing('entity_subscriptions', ['destination' => 'sixth@example.ru']);
    }

    #[Test]
    public function inactive_subscription_can_be_reactivated_within_limit(): void
    {
        $sub = EntitySubscription::create(['user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'back@example.ru', 'is_active' => false]);

        $this->actingAs($this->user)
            ->postJson('/cabinet/subscriptions/orders', ['email' => 'back@example.ru'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($sub->fresh()->is_active);
        $this->assertDatabaseCount('entity_subscriptions', 1);
    }

    #[Test]
    public function index_lists_only_own_email_subscriptions_of_section(): void
    {
        EntitySubscription::create(['user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'mine@example.ru']);
        EntitySubscription::create(['user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'mine2@example.ru']);
        $other = User::factory()->create();
        EntitySubscription::create(['user_id' => $other->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'foreign@example.ru']);

        $this->actingAs($this->user)
            ->getJson('/cabinet/subscriptions/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function user_cannot_delete_foreign_subscription(): void
    {
        $other = User::factory()->create();
        $foreign = EntitySubscription::create([
            'user_id' => $other->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'foreign@example.ru',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/cabinet/subscriptions/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('entity_subscriptions', ['id' => $foreign->id]);
    }

    #[Test]
    public function user_can_delete_own_subscription(): void
    {
        $sub = EntitySubscription::create([
            'user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'me@example.ru',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/cabinet/subscriptions/{$sub->id}")
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('entity_subscriptions', ['id' => $sub->id]);
    }

    #[Test]
    public function public_unsubscribe_link_deactivates_subscription(): void
    {
        $sub = EntitySubscription::create([
            'user_id' => $this->user->id, 'section' => 'orders', 'channel' => 'email', 'destination' => 'me@example.ru',
        ]);

        $this->get("/subscriptions/unsubscribe/{$sub->unsubscribe_token}")
            ->assertOk()
            ->assertSee('Вы отписаны');

        $this->assertFalse($sub->fresh()->is_active);
    }

    #[Test]
    public function guest_cannot_access_subscription_endpoints(): void
    {
        $this->postJson('/cabinet/subscriptions/orders', ['email' => 'a@b.ru'])
            ->assertUnauthorized();
    }
}
