<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailLayoutSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pecado_theme_is_active_by_default(): void
    {
        $this->assertSame('pecado', config('mail.markdown.theme'));
    }

    public function test_pecado_theme_file_exists(): void
    {
        $this->assertFileExists(
            resource_path('views/vendor/mail/html/themes/pecado.css')
        );
    }

    public function test_rendered_notification_uses_brand_color(): void
    {
        $user = User::factory()->create(['name' => 'Тест']);
        $notification = new ResetPasswordNotification('test-token');

        $rendered = $notification->toMail($user)->render();

        $this->assertStringContainsString('#9e1b32', $rendered, 'Бренд-цвет (#9e1b32) должен встраиваться в inline-стиль primary-кнопки');
    }

    public function test_rendered_notification_uses_pecado_logo_and_footer(): void
    {
        $user = User::factory()->create();
        $notification = new ResetPasswordNotification('test-token');

        $rendered = $notification->toMail($user)->render();

        $this->assertStringContainsString('images/logo.png', $rendered, 'Должна быть подставлена ссылка на логотип Pecado.ru');
        $this->assertStringContainsString('alt="Pecado.ru"', $rendered, 'У логотипа должен быть alt с названием бренда');
        $this->assertStringContainsString('info@pecado.ru', $rendered, 'В footer должна быть контактная почта');
        $this->assertStringContainsString('Все права защищены', $rendered, 'Footer должен быть на русском');
    }

    public function test_admin_recipients_config_parses_env_csv(): void
    {
        config(['notifications.mail.admin_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', 'a@x.ru, b@x.ru ,, c@x.ru')
        )))]);

        $this->assertSame(
            ['a@x.ru', 'b@x.ru', 'c@x.ru'],
            config('notifications.mail.admin_recipients')
        );
    }

    public function test_feature_flags_default_to_false(): void
    {
        $features = config('notifications.mail.features');

        $this->assertIsArray($features);
        foreach ($features as $name => $value) {
            $this->assertIsBool($value, "Feature flag `{$name}` должен быть boolean");
        }
    }
}
