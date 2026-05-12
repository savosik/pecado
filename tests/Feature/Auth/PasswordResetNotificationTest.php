<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_dispatches_localized_notification_to_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->post('/forgot-password', ['email' => 'someone@example.com'])
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_does_not_send_anything_for_unknown_email(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'existing@example.com']);

        $this->post('/forgot-password', ['email' => 'unknown@example.com']);

        Notification::assertNothingSent();
    }

    public function test_notification_subject_is_russian(): void
    {
        $user = User::factory()->create();
        $notification = new ResetPasswordNotification('test-token');

        $message = $notification->toMail($user);

        $this->assertSame('Сброс пароля — Pecado.ru', $message->subject);
    }

    public function test_notification_uses_pecado_markdown_template(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $notification = new ResetPasswordNotification('test-token');

        $message = $notification->toMail($user);

        $this->assertSame('mail.auth.reset-password', $message->markdown);
        $this->assertArrayHasKey('url', $message->viewData);
        $this->assertArrayHasKey('minutes', $message->viewData);
        $this->assertArrayHasKey('name', $message->viewData);
        $this->assertSame('Иван', $message->viewData['name']);
        $this->assertSame(60, $message->viewData['minutes']);
        $this->assertStringContainsString('test-token', $message->viewData['url']);
    }

    public function test_notification_is_queueable(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new ResetPasswordNotification('token')
        );
    }

    public function test_markdown_template_renders_without_errors(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $notification = new ResetPasswordNotification('test-token');

        $rendered = $notification->toMail($user)->render();

        $this->assertStringContainsString('Здравствуйте, Иван', $rendered);
        $this->assertStringContainsString('Сбросить пароль', $rendered);
        $this->assertStringContainsString('Pecado.ru', $rendered);
        $this->assertStringContainsString('test-token', $rendered);
    }
}
