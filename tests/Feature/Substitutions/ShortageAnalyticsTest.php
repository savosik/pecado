<?php

namespace Tests\Feature\Substitutions;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\SubstitutionOffer;
use App\Models\User;
use App\Notifications\Substitutions\PurchasingShortageReportNotification;
use App\Services\Substitution\ShortageAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Аналитика недоборов и отчёт закупкам (sub-08).
 */
class ShortageAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['substitutions.enabled' => true]);
    }

    #[Test]
    public function metrics_count_the_funnel_and_the_saved_amount_precisely(): void
    {
        $client = User::factory()->create();

        $source = Order::factory()->create(['user_id' => $client->id, 'total_amount' => 1000]);
        OrderItem::factory()->create([
            'order_id' => $source->id,
            'cancelled' => true,
            'subtotal' => 500,
        ]);

        SubstitutionOffer::factory()->confirmed()->create([
            'order_id' => $source->id,
            'user_id' => $client->id,
            'sent_at' => now()->subHours(2),
        ]);

        // Заказ-замена: спасённая сумма считается по полю связи, не эвристикой.
        $replacement = Order::factory()->create([
            'user_id' => $client->id,
            'total_amount' => 450,
        ]);
        $replacement->replacement_for_order_id = $source->id;
        $replacement->saveQuietly();

        // Шумовой заказ без связи — в спасённую сумму не попадает.
        Order::factory()->create(['user_id' => $client->id, 'total_amount' => 9999]);

        $metrics = app(ShortageAnalyticsService::class)->metrics(
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now()->addDay(),
        );

        $this->assertSame(1, $metrics['offers_total']);
        $this->assertSame(1, $metrics['offers_sent']);
        $this->assertSame(1, $metrics['offers_confirmed']);
        $this->assertSame(100, $metrics['coverage_pct']);
        $this->assertSame(100, $metrics['conversion_pct']);
        $this->assertSame(450.0, $metrics['saved_amount']);
        $this->assertSame(1, $metrics['cancelled_lines']);
    }

    #[Test]
    public function repeated_shortages_aggregate_by_product_over_the_window(): void
    {
        $product = Product::factory()->create(['name' => 'Дефицитный товар']);

        foreach (range(1, 3) as $i) {
            $order = Order::factory()->create();
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'cancelled' => true,
                'subtotal' => 100,
            ]);
        }

        $repeated = app(ShortageAnalyticsService::class)->repeatedShortages(90, 2);

        $this->assertCount(1, $repeated);
        $this->assertSame(3, $repeated[0]->shortages);
        $this->assertSame(300.0, $repeated[0]->lost_amount);
    }

    #[Test]
    public function the_purchasing_report_goes_to_configured_recipients(): void
    {
        Notification::fake();
        config(['notifications.mail.purchasing_recipients' => ['buyer@pecado.ru']]);

        $product = Product::factory()->create();
        foreach (range(1, 2) as $i) {
            $order = Order::factory()->create();
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'cancelled' => true,
                'subtotal' => 100,
            ]);
        }

        $this->artisan('substitutions:purchasing-report')->assertSuccessful();

        Notification::assertSentOnDemand(PurchasingShortageReportNotification::class);
    }

    #[Test]
    public function the_report_is_silent_without_recipients_or_data(): void
    {
        Notification::fake();
        config(['notifications.mail.purchasing_recipients' => []]);

        $this->artisan('substitutions:purchasing-report')
            ->expectsOutputToContain('Повторных недоборов за окно нет')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_analytics_page_opens_for_a_manager_with_the_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $manager->id]);

        $response = $this->actingAs($manager)->get('/crm/analytics/shortages');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('metrics', $props);
        $this->assertArrayHasKey('layers', $props);
        $this->assertArrayHasKey('retention', $props);
    }
}
