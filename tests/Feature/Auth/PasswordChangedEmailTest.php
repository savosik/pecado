<?php

namespace Tests\Feature\Auth;

use App\Events\UserPasswordChanged;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordChangedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.mail.features.password_changed' => true]);
    }

    public function test_cabinet_password_update_dispatches_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('old-password-123'),
        ]);

        $this->actingAs($user)
            ->put(route('cabinet.password.update'), [
                'current_password' => 'old-password-123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertSessionHas('success');

        Notification::assertSentTo($user, PasswordChangedNotification::class, function ($n) {
            return $n->source === 'cabinet';
        });
    }

    public function test_password_reset_via_token_dispatches_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset-me@example.com',
            'password' => Hash::make('old-password-123'),
        ]);

        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'reset-me@example.com',
            'password' => 'brand-new-987',
            'password_confirmation' => 'brand-new-987',
        ])->assertRedirect('/login');

        Notification::assertSentTo($user, PasswordChangedNotification::class, function ($n) {
            return $n->source === 'reset';
        });
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.password_changed' => false]);

        $user = User::factory()->create();

        UserPasswordChanged::dispatch($user, 'cabinet', '127.0.0.1');

        Notification::assertNothingSent();
    }

    public function test_notification_subject_is_russian(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $notification = new PasswordChangedNotification('cabinet', '203.0.113.7');

        $message = $notification->toMail($user);

        $this->assertSame('Ваш пароль изменён — Pecado.ru', $message->subject);
        $this->assertSame('mail.auth.password-changed', $message->markdown);
        $this->assertSame('Иван', $message->viewData['name']);
        $this->assertSame('203.0.113.7', $message->viewData['ip']);
        $this->assertStringContainsString('личный кабинет', $message->viewData['sourceLabel']);
    }

    public function test_notification_renders_with_reset_source_label(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);

        $rendered = (new PasswordChangedNotification('reset', null))->toMail($user)->render();

        $this->assertStringContainsString('Здравствуйте, Иван', $rendered);
        $this->assertStringContainsString('сброса пароля', $rendered);
        $this->assertStringContainsString('Это были не вы', $rendered);
    }

    public function test_notification_is_queueable(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new PasswordChangedNotification('cabinet'),
        );
    }
}
