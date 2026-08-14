<?php

namespace Tests\Feature\Substitutions;

use App\Enums\Substitution\OfferStatus;
use App\Models\CrmEmail;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\SubstitutionOffer;
use App\Models\User;
use App\Notifications\Substitutions\SubstitutionReminderNotification;
use App\Services\Substitution\SubstitutionOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Письма и механика дожима (sub-05): черновик при рождении оффера,
 * одно напоминание, задача «позвонить», просрочка — на замороженном времени.
 */
class SubstitutionFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['substitutions.enabled' => true]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeOffer(array $attributes = []): SubstitutionOffer
    {
        $managerAccount = User::factory()->create();
        $client = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $client->id, 'erp_number' => '29УТ-011777']);

        return SubstitutionOffer::factory()->create(array_merge([
            'order_id' => $order->id,
            'user_id' => $client->id,
            'manager_user_id' => $managerAccount->id,
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }

    #[Test]
    public function a_draft_letter_is_created_together_with_the_offer(): void
    {
        $managerAccount = User::factory()->create(['email' => 'manager@pecado.ru']);
        $manager = PersonalManager::factory()->create(['user_id' => $managerAccount->id]);
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'personal_manager_id' => $manager->id,
        ]);

        $order = Order::factory()->create(['user_id' => $client->id, 'erp_number' => '29УТ-011777']);
        $product = Product::factory()->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'cancelled' => true,
            'quantity' => 2,
        ]);

        $offer = app(SubstitutionOfferService::class)->registerCancellation($order, [$item->id]);

        $this->assertNotNull($offer->crm_email_id);

        $draft = CrmEmail::find($offer->crm_email_id);
        $this->assertSame(['client@example.com'], $draft->to);
        $this->assertSame('manager@pecado.ru', $draft->reply_to);
        $this->assertSame('draft', $draft->status->value);
        $this->assertStringContainsString('29УТ-011777', $draft->subject);
        $this->assertStringContainsString('не прошла контроль', $draft->body_html);
    }

    #[Test]
    public function exactly_one_reminder_goes_out_for_an_unopened_offer(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        $this->makeOffer(['sent_at' => now()->subHours(25)]);

        $this->artisan('substitutions:follow-up')->assertSuccessful();

        Notification::assertSentOnDemand(SubstitutionReminderNotification::class);
        Notification::assertCount(1);

        // Второй тик: напоминание строго одно.
        $this->artisan('substitutions:follow-up')->assertSuccessful();
        Notification::assertCount(1);
    }

    #[Test]
    public function opening_the_page_cancels_the_reminder(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        $this->makeOffer([
            'sent_at' => now()->subHours(30),
            'status' => OfferStatus::VIEWED,
            'viewed_at' => now()->subHour(),
        ]);

        $this->artisan('substitutions:follow-up')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function silence_after_viewing_creates_a_call_task_once(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        $offer = $this->makeOffer([
            'status' => OfferStatus::VIEWED,
            'viewed_at' => now()->subHours(49),
        ]);

        $this->artisan('substitutions:follow-up')->assertSuccessful();

        $task = CrmTask::sole();
        $this->assertStringContainsString('Позвонить по недобору', $task->title);
        $this->assertSame($offer->manager_user_id, $task->assignee_id);
        $this->assertNotNull($offer->fresh()->call_task_at);

        $this->artisan('substitutions:follow-up')->assertSuccessful();
        $this->assertSame(1, CrmTask::count());
    }

    #[Test]
    public function an_overdue_offer_expires_with_a_final_task(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        $offer = $this->makeOffer(['expires_at' => now()->subHour()]);

        $this->artisan('substitutions:follow-up')->assertSuccessful();

        $this->assertSame(OfferStatus::EXPIRED, $offer->fresh()->status);
        $this->assertStringContainsString('просрочена', CrmTask::sole()->title);
    }

    #[Test]
    public function dry_run_reports_but_changes_nothing(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        $offer = $this->makeOffer(['sent_at' => now()->subHours(25)]);
        $expired = $this->makeOffer(['expires_at' => now()->subHour()]);

        $this->artisan('substitutions:follow-up --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($offer->fresh()->reminded_at);
        $this->assertSame(OfferStatus::PENDING, $expired->fresh()->status);
        $this->assertSame(0, CrmTask::count());
    }

    #[Test]
    public function follow_up_is_inert_when_the_feature_flag_is_off(): void
    {
        config(['substitutions.enabled' => false]);
        Carbon::setTestNow('2026-08-14 12:00:00');

        $this->makeOffer(['sent_at' => now()->subHours(25)]);

        $this->artisan('substitutions:follow-up')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
