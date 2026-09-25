<?php

namespace Tests\Feature\Pickup;

use App\Events\Pickup\GoodsIssueHandedOver;
use App\Models\GoodsIssue;
use App\Models\Pickup\PickupHandover;
use App\Models\User;
use App\Services\Pickup\HandoverException;
use App\Services\Pickup\HandoverService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-06: отметка «выдан» — одна на ордер, с автором, отменяемая с причиной в окне. */
class HandoverServiceTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    private HandoverService $service;

    private User $keeper;

    private GoodsIssue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.cancel_window_hours' => 24]);
        $this->service = app(HandoverService::class);
        $this->keeper = User::factory()->create(['name' => 'Иванов']);
        $this->issue = $this->goodsIssueFor($this->pickupOrder($this->pickupClient()));
    }

    #[Test]
    public function issue_records_who_when_and_how(): void
    {
        Event::fake([GoodsIssueHandedOver::class]);

        $handover = $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL, null, ['recipient_name' => ' Курьер Олег ', 'comment' => '']);

        $this->assertSame($this->keeper->id, $handover->issued_by);
        $this->assertSame('Курьер Олег', $handover->recipient_name);
        $this->assertNull($handover->comment);
        $this->assertSame(2, $handover->packages_count);
        $this->assertNotNull($this->issue->fresh()->activeHandover);
        Event::assertDispatched(GoodsIssueHandedOver::class);
    }

    #[Test]
    public function second_issue_is_rejected_with_name_of_first_keeper(): void
    {
        $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL);

        try {
            $this->service->issue($this->issue, User::factory()->create(), PickupHandover::METHOD_QR);
            $this->fail('двойная выдача должна быть отклонена');
        } catch (HandoverException $e) {
            $this->assertSame('already_issued', $e->reason);
            $this->assertStringContainsString('Иванов', $e->getMessage());
        }

        $this->assertSame(1, PickupHandover::count());
    }

    #[Test]
    public function database_forbids_two_active_handovers_for_one_goods_issue(): void
    {
        $row = ['goods_issue_id' => $this->issue->id, 'issued_by' => $this->keeper->id, 'issued_at' => now(), 'method' => 'manual'];
        PickupHandover::create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        PickupHandover::create($row);
    }

    #[Test]
    public function not_shipped_and_deleted_goods_issues_cannot_be_issued(): void
    {
        $picking = $this->goodsIssueFor($this->pickupOrder($this->pickupClient()), GoodsIssue::STATUS_TO_PICK);
        try {
            $this->service->issue($picking, $this->keeper, PickupHandover::METHOD_MANUAL);
            $this->fail();
        } catch (HandoverException $e) {
            $this->assertSame('not_ready', $e->reason);
        }

        $this->issue->delete();
        try {
            $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL);
            $this->fail();
        } catch (HandoverException $e) {
            $this->assertSame('deleted', $e->reason);
        }
    }

    #[Test]
    public function cancel_requires_reason_and_frees_goods_issue_for_new_handover(): void
    {
        $handover = $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL);

        try {
            $this->service->cancel($handover, $this->keeper, '  ');
            $this->fail();
        } catch (HandoverException $e) {
            $this->assertSame('reason_required', $e->reason);
        }

        $this->service->cancel($handover, $this->keeper, 'Отметили не тот ордер');
        $this->assertNull($this->issue->fresh()->activeHandover);

        $again = $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL);
        $this->assertNotSame($handover->id, $again->id);
        $this->assertSame(2, PickupHandover::count());
    }

    #[Test]
    public function cancel_is_closed_after_window(): void
    {
        $handover = $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL);
        $handover->forceFill(['issued_at' => now()->subHours(25)])->save();

        try {
            $this->service->cancel($handover, $this->keeper, 'поздно спохватились');
            $this->fail();
        } catch (HandoverException $e) {
            $this->assertSame('cancel_window_closed', $e->reason);
        }
    }

    #[Test]
    public function rollback_of_handed_goods_issue_raises_review_flag_but_keeps_handover(): void
    {
        $handover = $this->service->issue($this->issue, $this->keeper, PickupHandover::METHOD_MANUAL);

        $this->moveIssue($this->issue, GoodsIssue::STATUS_TO_PICK);

        $handover->refresh();
        $this->assertTrue($handover->needs_review);
        $this->assertNull($handover->cancelled_at);
        $this->assertStringContainsString('К отбору', $handover->review_note);
    }

    #[Test]
    public function backfill_closes_pre_launch_tail_in_any_status(): void
    {
        $tail = $this->goodsIssueFor($this->pickupOrder($this->pickupClient()), GoodsIssue::STATUS_TO_SHIP);

        $handover = $this->service->closeWithoutHandover($tail, $this->keeper);

        $this->assertSame(PickupHandover::METHOD_BACKFILL, $handover->method);
    }
}
