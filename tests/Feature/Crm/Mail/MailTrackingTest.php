<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Jobs\SendCrmEmailJob;
use App\Mail\CrmManagerMail;
use App\Models\CrmEmail;
use App\Models\CrmEmailDelivery;
use App\Models\CrmEmailEvent;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailDeliveryLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Отслеживание прочтения писем.
 *
 * Что здесь проверяется по существу: адресаты различимы, редирект не открытый,
 * а учёт никогда не портит человеку письмо.
 */
class MailTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);

        config(['notifications.mail.features.crm_outbound' => true, 'mail_stream.tracking' => true]);
    }

    private function send(array $to, string $body = '<p>Здравствуйте</p>'): CrmEmail
    {
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'to' => $to,
            'body_html' => $body,
            'status' => EmailStatus::QUEUED,
        ]);

        (new SendCrmEmailJob($letter))->handle(app(MailDeliveryLedger::class));

        return $letter->fresh();
    }

    #[Test]
    public function each_recipient_gets_their_own_copy_and_own_token(): void
    {
        // Один пиксель на всех не отвечает на вопрос «кто именно открыл».
        $letter = $this->send(['buh@romashka.ru', 'dir@romashka.ru']);

        Mail::assertSent(CrmManagerMail::class, 2);

        $tokens = CrmEmailDelivery::query()->where('crm_email_id', $letter->id)->pluck('track_token');

        $this->assertCount(2, $tokens);
        $this->assertCount(2, $tokens->unique());
        $this->assertSame(EmailStatus::SENT, $letter->status);
    }

    #[Test]
    public function open_is_recorded_against_the_person_who_opened(): void
    {
        $letter = $this->send(['buh@romashka.ru', 'dir@romashka.ru']);

        $buh = CrmEmailDelivery::query()->where('recipient', 'buh@romashka.ru')->firstOrFail();

        $this->get(route('mail.track.open', ['token' => $buh->track_token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');

        $buh->refresh();

        $this->assertNotNull($buh->opened_at);
        $this->assertSame(1, $buh->opens_count);

        // Второй адресат письма не открывал — и это должно быть видно.
        $dir = CrmEmailDelivery::query()->where('recipient', 'dir@romashka.ru')->firstOrFail();
        $this->assertNull($dir->opened_at);
    }

    #[Test]
    public function repeat_opens_are_counted_but_the_first_one_stays_first(): void
    {
        // Вопрос «когда впервые увидели» важнее, чем «когда в последний раз».
        $letter = $this->send(['buh@romashka.ru']);
        $delivery = CrmEmailDelivery::query()->firstOrFail();

        $this->get(route('mail.track.open', ['token' => $delivery->track_token]))->assertOk();
        $first = $delivery->fresh()->opened_at;

        $this->travel(2)->minutes();
        $this->get(route('mail.track.open', ['token' => $delivery->track_token]))->assertOk();

        $delivery->refresh();

        $this->assertSame(2, $delivery->opens_count);
        $this->assertTrue($first->equalTo($delivery->opened_at));
        $this->assertTrue($delivery->last_opened_at->gt($first));
    }

    #[Test]
    public function links_are_rewritten_and_a_click_is_recorded(): void
    {
        $this->send(['buh@romashka.ru'], '<p>Смотрите <a href="https://pecado.ru/cabinet/documents">документы</a></p>');

        $delivery = CrmEmailDelivery::query()->firstOrFail();

        Mail::assertSent(CrmManagerMail::class, function (CrmManagerMail $mail) use ($delivery): bool {
            $html = $mail->content()->with['bodyHtml'];

            return str_contains($html, '/e/c/'.$delivery->track_token)
                && ! str_contains($html, 'href="https://pecado.ru/cabinet/documents"');
        });

        $signed = URL::signedRoute('mail.track.click', [
            'token' => $delivery->track_token,
            'u' => base64_encode('https://pecado.ru/cabinet/documents'),
        ]);

        $this->get($signed)->assertRedirect('https://pecado.ru/cabinet/documents');

        $delivery->refresh();

        $this->assertSame(1, $delivery->clicks_count);
        // Переход означает, что письмо открывали, даже если картинка не загрузилась.
        $this->assertNotNull($delivery->opened_at);
    }

    #[Test]
    public function redirect_cannot_be_forged_into_a_foreign_site(): void
    {
        // Без подписи эндпоинт был бы открытым редиректом, и нашим доменом
        // уводили бы людей на фишинг.
        $this->send(['buh@romashka.ru']);
        $delivery = CrmEmailDelivery::query()->firstOrFail();

        $unsigned = route('mail.track.click', [
            'token' => $delivery->track_token,
            'u' => base64_encode('https://evil.example.com'),
        ]);

        $this->get($unsigned)->assertForbidden();
        $this->assertSame(0, $delivery->fresh()->clicks_count);
    }

    #[Test]
    public function signed_but_dangerous_scheme_goes_home_instead(): void
    {
        // Подпись говорит «ссылка наша», а не «ссылка безопасна».
        $this->send(['buh@romashka.ru']);
        $delivery = CrmEmailDelivery::query()->firstOrFail();

        $signed = URL::signedRoute('mail.track.click', [
            'token' => $delivery->track_token,
            'u' => base64_encode('javascript:alert(1)'),
        ]);

        $this->get($signed)->assertRedirect(config('app.url'));
    }

    #[Test]
    public function unknown_token_still_returns_a_picture(): void
    {
        // Иначе в письме появится битая картинка, и человек это увидит.
        $this->get(route('mail.track.open', ['token' => str_repeat('z', 40)]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
    }

    #[Test]
    public function letter_with_tracking_off_carries_no_pixel(): void
    {
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'to' => ['buh@romashka.ru'],
            'tracking_enabled' => false,
            'status' => EmailStatus::QUEUED,
        ]);

        (new SendCrmEmailJob($letter))->handle(app(MailDeliveryLedger::class));

        Mail::assertSent(CrmManagerMail::class, function (CrmManagerMail $mail): bool {
            return $mail->content()->with['trackingPixel'] === '';
        });
    }

    #[Test]
    public function global_switch_turns_tracking_off_everywhere(): void
    {
        config(['mail_stream.tracking' => false]);

        $this->send(['buh@romashka.ru'], '<p><a href="https://pecado.ru/x">ссылка</a></p>');

        Mail::assertSent(CrmManagerMail::class, function (CrmManagerMail $mail): bool {
            $content = $mail->content();

            return $content->with['trackingPixel'] === ''
                && str_contains($content->with['bodyHtml'], 'href="https://pecado.ru/x"');
        });
    }

    #[Test]
    public function every_event_is_logged_with_enough_detail_to_judge_it(): void
    {
        // По User-Agent отличается предзагрузка Apple и Gmail от живого человека —
        // без него открытия невозможно интерпретировать.
        $this->send(['buh@romashka.ru']);
        $delivery = CrmEmailDelivery::query()->firstOrFail();

        $this->withHeaders(['User-Agent' => 'GoogleImageProxy'])
            ->get(route('mail.track.open', ['token' => $delivery->track_token]))
            ->assertOk();

        $event = CrmEmailEvent::query()->firstOrFail();

        $this->assertSame(CrmEmailEvent::TYPE_OPEN, $event->type);
        $this->assertSame('GoogleImageProxy', $event->user_agent);
        $this->assertNotNull($event->ip);
    }

    #[Test]
    public function carbon_copy_is_not_multiplied_across_copies(): void
    {
        // Письмо уходит каждому адресату отдельно; без защиты человек в копии
        // получил бы столько писем, сколько у письма получателей.
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'to' => ['buh@romashka.ru', 'dir@romashka.ru'],
            'cc' => ['boss@romashka.ru'],
            'status' => EmailStatus::QUEUED,
        ]);

        (new SendCrmEmailJob($letter))->handle(app(MailDeliveryLedger::class));

        $withCc = 0;

        Mail::assertSent(CrmManagerMail::class, function (CrmManagerMail $mail) use (&$withCc): bool {
            if ($mail->envelope()->cc !== []) {
                $withCc++;
            }

            return true;
        });

        $this->assertSame(1, $withCc);
    }
}
