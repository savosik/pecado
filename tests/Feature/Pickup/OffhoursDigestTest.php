<?php

namespace Tests\Feature\Pickup;

use App\Models\Company;
use App\Models\GoodsIssue;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Pickup\OffhoursDigestNotification;
use App\Services\Pickup\HandoverService;
use App\Services\Pickup\OffhoursDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-13: утром менеджер узнаёт, что его клиенты сделали вечером и в субботу. */
class OffhoursDigestTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.handover_since' => null, 'production_calendar.holidays' => []]);

        $this->manager = User::factory()->create(['email' => 'manager@pecado.test']);
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
        Company::factory()->create(['user_id' => $this->client->id]);
    }

    /** Ближайший понедельник 09:00 — от today(), без календарных бомб. */
    private function monday(): Carbon
    {
        return Carbon::today()->next(Carbon::MONDAY)->setTime(9, 0);
    }

    #[Test]
    public function monday_window_starts_friday_evening(): void
    {
        [$from, $to] = app(OffhoursDigest::class)->window($this->monday());

        $this->assertSame(Carbon::FRIDAY, $from->dayOfWeek);
        $this->assertSame('18:00', $from->format('H:i'));
        $this->assertTrue($to->equalTo($this->monday()));
    }

    #[Test]
    public function digest_tells_manager_what_happened_on_saturday(): void
    {
        $saturday = $this->monday()->copy()->subDays(2)->setTime(19, 30);
        Carbon::setTestNow($saturday);

        $handedIssue = $this->goodsIssueFor($this->pickupOrder($this->client, ['erp_number' => '29УТ-020001']), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($handedIssue, GoodsIssue::STATUS_SHIPPED);
        app(HandoverService::class)->issue($handedIssue, User::factory()->create(), 'manual', null, ['recipient_name' => 'Олег']);

        $waitingIssue = $this->goodsIssueFor($this->pickupOrder($this->client, ['erp_number' => '29УТ-020002']), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($waitingIssue, GoodsIssue::STATUS_SHIPPED);

        $this->pickupOrder($this->client, ['erp_number' => '29УТ-020003', 'reserve_outcome' => 'expired']);

        // Клиент без менеджера в сводку не попадает — слать некому.
        $this->goodsIssueFor($this->pickupOrder($this->pickupClient()));

        Carbon::setTestNow($this->monday());
        $groups = app(OffhoursDigest::class)->build(now());

        $this->assertCount(1, $groups);
        $this->assertSame($this->manager->id, $groups[0]['recipient']->id);
        $this->assertSame(['29УТ-020001'], array_column($groups[0]['sections']['handed'], 'number'));
        $this->assertStringContainsString('Олег', $groups[0]['sections']['handed'][0]['note']);
        $this->assertSame(['29УТ-020002'], array_column($groups[0]['sections']['not_picked'], 'number'));
        $this->assertSame(['29УТ-020003'], array_column($groups[0]['sections']['reserve_lost'], 'number'));
    }

    #[Test]
    public function command_sends_once_and_stays_silent_when_nothing_happened(): void
    {
        Notification::fake();
        Carbon::setTestNow($this->monday());

        $this->artisan('pickup:offhours-digest')->assertSuccessful();
        Notification::assertNothingSent();

        Carbon::setTestNow($this->monday()->copy()->subDays(2)->setTime(12, 0));
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_SHIP);
        $this->moveIssue($issue, GoodsIssue::STATUS_SHIPPED);

        Carbon::setTestNow($this->monday());
        $this->artisan('pickup:offhours-digest')->assertSuccessful();
        $this->artisan('pickup:offhours-digest')->assertSuccessful();

        Notification::assertSentToTimes($this->manager, OffhoursDigestNotification::class, 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
