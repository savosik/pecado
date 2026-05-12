<?php

namespace Tests\Feature\Auth;

use App\Events\UserRegisteredOnSite;
use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        config(['notifications.mail.features.welcome_on_registration' => true]);
    }

    public function test_web_registration_dispatches_welcome(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Иван',
            'email' => 'newbie@example.com',
            'phone' => '+79991234567',
            'country' => 'RU',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => '1',
        ])->assertRedirect('/onboarding');

        $user = User::where('email', 'newbie@example.com')->firstOrFail();
        Notification::assertSentTo($user, WelcomeNotification::class);
    }

    public function test_disabled_feature_flag_skips_email(): void
    {
        Notification::fake();
        config(['notifications.mail.features.welcome_on_registration' => false]);

        $user = User::factory()->create();

        UserRegisteredOnSite::dispatch($user, 'web');

        Notification::assertNothingSent();
    }

    public function test_partner_from_erp_does_not_receive_welcome(): void
    {
        Notification::fake();

        $partner = User::factory()->create([
            'erp_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        UserRegisteredOnSite::dispatch($partner, 'admin');

        Notification::assertNothingSent();
    }

    public function test_admin_creating_user_without_checkbox_does_not_send(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Created By Admin',
            'email' => 'silent@example.com',
            'password' => 'password123',
        ]);

        Notification::assertNothingSent();
    }

    public function test_admin_creating_user_with_checkbox_sends_welcome(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'With Welcome',
            'email' => 'invited@example.com',
            'password' => 'password123',
            'send_welcome_email' => '1',
        ]);

        $created = User::where('email', 'invited@example.com')->firstOrFail();
        Notification::assertSentTo($created, WelcomeNotification::class);
    }

    public function test_welcome_notification_subject_is_russian(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        $notification = new WelcomeNotification('web');

        $message = $notification->toMail($user);

        $this->assertSame('Добро пожаловать в Pecado.ru', $message->subject);
        $this->assertSame('mail.auth.welcome', $message->markdown);
        $this->assertSame('Иван', $message->viewData['name']);
        $this->assertStringContainsString('/cabinet/profile', $message->viewData['cabinetUrl']);
    }

    public function test_welcome_notification_renders(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);

        $rendered = (new WelcomeNotification('web'))->toMail($user)->render();

        $this->assertStringContainsString('Добро пожаловать в Pecado.ru, Иван', $rendered);
        $this->assertStringContainsString('Перейти в личный кабинет', $rendered);
        $this->assertStringContainsString('cabinet/profile', $rendered);
    }
}
