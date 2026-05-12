<?php

namespace Tests\Feature\Mail;

use App\Enums\ReturnStatus;
use App\Events\ReturnStatusChanged;
use App\Models\ProductReturn;
use App\Models\User;
use App\Notifications\Returns\ReturnStatusChangedNotification;
use App\Services\Erp\Handlers\HandleReturnUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReturnStatusChangedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.mail.features.return_status_changes' => true]);
    }

    public function test_handle_return_updated_dispatches_notification_on_status_change(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'uuid' => '00000000-0000-0000-0000-000000000077',
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        (new HandleReturnUpdated)->handle([
            'uuid' => $return->uuid,
            'status' => 'completed',
        ]);

        Notification::assertSentTo($user, ReturnStatusChangedNotification::class, function ($n) use ($return) {
            return $n->productReturn->is($return)
                && $n->productReturn->status === ReturnStatus::COMPLETED
                && $n->previousStatus === ReturnStatus::PENDING_APPROVAL;
        });
    }

    public function test_same_status_does_not_dispatch(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'uuid' => '00000000-0000-0000-0000-000000000078',
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        (new HandleReturnUpdated)->handle([
            'uuid' => $return->uuid,
            'status' => 'pending_approval',
        ]);

        Notification::assertNothingSent();
    }

    public function test_only_number_change_does_not_dispatch(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'uuid' => '00000000-0000-0000-0000-000000000079',
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        (new HandleReturnUpdated)->handle([
            'uuid' => $return->uuid,
            'number' => 'VOZ-NEW',
        ]);

        Notification::assertNothingSent();
    }

    public function test_unknown_status_does_not_dispatch(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'uuid' => '00000000-0000-0000-0000-000000000080',
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        (new HandleReturnUpdated)->handle([
            'uuid' => $return->uuid,
            'status' => 'completely_unknown_status',
        ]);

        Notification::assertNothingSent();
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.return_status_changes' => false]);

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        ReturnStatusChanged::dispatch($return, ReturnStatus::PENDING_APPROVAL);

        Notification::assertNothingSent();
    }

    public function test_notification_subject_and_payload(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'erp_number' => 'VOZ-200',
            'status' => ReturnStatus::COMPLETED,
        ]);

        $message = (new ReturnStatusChangedNotification($return, ReturnStatus::FOR_RETURN))
            ->toMail($user);

        $this->assertSame('Возврат VOZ-200: Выполнена — Pecado.ru', $message->subject);
        $this->assertSame('mail.returns.status-changed', $message->markdown);
        $this->assertSame(ReturnStatus::COMPLETED, $message->viewData['newStatus']);
        $this->assertSame(ReturnStatus::FOR_RETURN, $message->viewData['previousStatus']);
        $this->assertStringContainsString('зачислены', $message->viewData['message']);
    }

    public function test_rejected_renders_admin_comment(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'status' => ReturnStatus::REJECTED,
            'admin_comment' => 'Истёк срок гарантии',
        ]);

        $rendered = (new ReturnStatusChangedNotification($return, ReturnStatus::PENDING_APPROVAL))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Отклонена', $rendered);
        $this->assertStringContainsString('Истёк срок гарантии', $rendered);
        $this->assertStringContainsString('Открыть заявку в кабинете', $rendered);
    }

    public function test_notification_is_queueable(): void
    {
        $user = User::factory()->create();
        $return = ProductReturn::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new ReturnStatusChangedNotification($return, ReturnStatus::PENDING_APPROVAL),
        );
    }
}
