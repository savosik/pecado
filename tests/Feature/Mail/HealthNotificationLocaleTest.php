<?php

namespace Tests\Feature\Mail;

use App\Notifications\Health\HealthCheckFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Checks\Result;
use Tests\TestCase;

class HealthNotificationLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_config_uses_localized_notification_class(): void
    {
        $configured = array_key_first(config('health.notifications.notifications'));

        $this->assertSame(HealthCheckFailedNotification::class, $configured);
    }

    public function test_health_config_to_is_an_array(): void
    {
        $to = config('health.notifications.mail.to');

        $this->assertIsArray($to, 'HEALTH_NOTIFY_EMAIL должен парситься в массив, чтобы Spatie слал всем получателям');
        $this->assertNotEmpty($to);
    }

    public function test_notification_subject_is_russian(): void
    {
        config(['app.name' => 'Pecado.ru']);

        $result = (new Result(\Spatie\Health\Enums\Status::failed(), 'Диск переполнен'))
            ->check(UsedDiskSpaceCheck::new());

        $message = (new HealthCheckFailedNotification([$result]))->toMail();

        $this->assertStringContainsString('Pecado.ru: проверка здоровья не пройдена', $message->subject);
        $this->assertSame('mail.health.check-failed', $message->markdown);
    }

    public function test_notification_renders_with_results(): void
    {
        config(['app.name' => 'Pecado.ru']);

        $result = (new Result(\Spatie\Health\Enums\Status::failed(), 'Диск переполнен'))
            ->check(UsedDiskSpaceCheck::new());

        $rendered = (new HealthCheckFailedNotification([$result]))->toMail()->render();

        $this->assertStringContainsString('Проверка здоровья не пройдена', $rendered);
        $this->assertStringContainsString('Диск переполнен', $rendered);
        $this->assertStringContainsString('не пройдена', $rendered);
    }

    public function test_notification_renders_warning_label_in_russian(): void
    {
        $result = (new Result(\Spatie\Health\Enums\Status::warning(), 'Диск почти полон'))
            ->check(UsedDiskSpaceCheck::new());

        $rendered = (new HealthCheckFailedNotification([$result]))->toMail()->render();

        $this->assertStringContainsString('предупреждение', $rendered);
        $this->assertStringContainsString('Диск почти полон', $rendered);
    }
}
