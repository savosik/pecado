<?php

namespace Tests\Feature\Mail;

use App\Enums\ReturnStatus;
use App\Events\ReturnCreated;
use App\Models\ProductReturn;
use App\Models\User;
use App\Notifications\Returns\ReturnCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReturnCreatedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.mail.features.return_created' => true]);
    }

    public function test_dispatching_return_created_sends_notification_to_client(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'status' => ReturnStatus::PENDING_APPROVAL,
        ]);

        ReturnCreated::dispatch($return);

        Notification::assertSentTo($user, ReturnCreatedNotification::class, function ($n) use ($return) {
            return $n->productReturn->is($return);
        });
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.return_created' => false]);

        $user = User::factory()->create();
        $return = ProductReturn::factory()->create(['user_id' => $user->id]);

        ReturnCreated::dispatch($return);

        Notification::assertNothingSent();
    }

    public function test_notification_subject_uses_erp_number_when_available(): void
    {
        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'erp_number' => 'VOZ-007',
        ]);

        $message = (new ReturnCreatedNotification($return))->toMail($user);

        $this->assertSame('Заявка на возврат VOZ-007 принята — Pecado.ru', $message->subject);
        $this->assertSame('mail.returns.created', $message->markdown);
        $this->assertSame('VOZ-007', $message->viewData['returnNumber']);
    }

    public function test_notification_subject_falls_back_to_internal_id(): void
    {
        $user = User::factory()->create();
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'erp_number' => null,
        ]);

        $message = (new ReturnCreatedNotification($return))->toMail($user);

        $this->assertStringContainsString('Заявка на возврат №'.$return->id.' принята', $message->subject);
    }

    public function test_notification_renders_status_label_and_total(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $return = ProductReturn::factory()->create([
            'user_id' => $user->id,
            'status' => ReturnStatus::PENDING_APPROVAL,
            'total_amount' => 2500.00,
        ]);

        $rendered = (new ReturnCreatedNotification($return))->toMail($user)->render();

        $this->assertStringContainsString('Здравствуйте, Иван', $rendered);
        $this->assertStringContainsString('На согласовании', $rendered);
        $this->assertStringContainsString('2 500,00 ₽', $rendered);
        $this->assertStringContainsString('Открыть заявку в кабинете', $rendered);
    }

    public function test_notification_is_queueable(): void
    {
        $user = User::factory()->create();
        $return = ProductReturn::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new ReturnCreatedNotification($return),
        );
    }
}
