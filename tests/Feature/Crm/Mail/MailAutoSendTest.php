<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\NotificationSuppression;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Автоотправка — галочка на правиле, а не режим системы.
 *
 * Пока галочка снята, правило только проставляет получателей: менеджер видит,
 * что отбирается верно, и отправляет сам. Это и есть безопасный запуск —
 * не общий рубильник, который страшно трогать, а свойство каждого фильтра.
 */
class MailAutoSendTest extends TestCase
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
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config([
            'mail_stream.enabled' => true,
            'mail_stream.autosend' => true,
            'notifications.mail.features.crm_outbound' => true,
        ]);
    }

    private function letter(): CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
            clientUserId: $this->client->id,
            data: ['document_type' => 'reconciliation_act', 'document_number' => '1023', 'document_title' => 'Акт сверки'],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен'],
        ));
    }

    #[Test]
    public function without_the_checkbox_letter_waits_for_a_human(): void
    {
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();

        $letter = $this->letter();

        $this->assertSame(EmailStatus::DRAFT, $letter->status);
        $this->assertSame(['buh@romashka.ru'], $letter->to);
        $this->assertNull($letter->auto_sent_rule_id);
    }

    #[Test]
    public function with_the_checkbox_letter_leaves_by_itself(): void
    {
        $rule = CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->auto()->create();

        $letter = $this->letter();

        $this->assertSame(EmailStatus::SENT, $letter->status);
        $this->assertSame($rule->id, $letter->auto_sent_rule_id);
    }

    #[Test]
    public function global_switch_stops_everything(): void
    {
        // Последняя возможность остановить поток одной переменной.
        config(['mail_stream.autosend' => false]);
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->auto()->create();

        $letter = $this->letter();

        $this->assertSame(EmailStatus::DRAFT, $letter->status);
        $this->assertStringContainsString('выключена', (string) $letter->skip_reason);
    }

    #[Test]
    public function suppressed_address_is_refused_with_a_visible_reason(): void
    {
        NotificationSuppression::query()->create([
            'email' => 'buh@romashka.ru',
            'scope' => NotificationSuppression::SCOPE_ALL,
            'reason' => NotificationSuppression::REASON_UNSUBSCRIBED,
        ]);

        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->auto()->create();

        $letter = $this->letter();

        // Молчание здесь недопустимо: менеджер, уверенный, что письмо ушло,
        // не должен узнавать обратное от клиента.
        $this->assertSame(EmailStatus::DRAFT, $letter->status);
        $this->assertStringContainsString('стоп-листе', (string) $letter->skip_reason);
    }

    #[Test]
    public function throttle_stops_a_series_to_one_address(): void
    {
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->auto()
            ->create(['throttle_minutes' => 120]);

        $first = $this->letter();
        $this->assertSame(EmailStatus::SENT, $first->status);

        // Второй повод по другому документу — письмо новое, но адрес тот же.
        $second = app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
            clientUserId: $this->client->id,
            data: ['document_type' => 'reconciliation_act', 'document_number' => '1024', 'document_title' => 'Акт сверки'],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен'],
        ));

        $this->assertSame(EmailStatus::DRAFT, $second->status);
        $this->assertStringContainsString('Слишком часто', (string) $second->skip_reason);
    }

    #[Test]
    public function rule_without_resolvable_recipient_says_so(): void
    {
        $orphan = User::factory()->create(['email' => 'orphan@example.com']);

        CrmMailRule::factory()->byTag('акт-сверки')->to(['менеджер'])->auto()->create();

        $letter = app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
            clientUserId: $orphan->id,
            data: ['document_type' => 'reconciliation_act', 'document_number' => '77', 'document_title' => 'Акт сверки'],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен'],
        ));

        $this->assertNotNull($letter);
        $this->assertSame(EmailStatus::DRAFT, $letter->status);
        $this->assertStringContainsString('получателя', (string) $letter->skip_reason);
    }

    #[Test]
    public function unsubscribe_link_from_an_old_letter_still_stops_the_stream(): void
    {
        // Ссылка из уже разосланного письма обязана работать и после демонтажа
        // пульта. Больше того: отписавшийся адрес должен замолчать и в новом
        // потоке — иначе человек нажал «отписаться», а письма идут дальше.
        $subscription = \App\Models\EntitySubscription::query()->create([
            'user_id' => $this->client->id,
            'section' => 'orders',
            'channel' => 'email',
            'destination' => 'buh@romashka.ru',
            'is_active' => true,
        ]);

        $this->get(route('subscriptions.unsubscribe', $subscription->unsubscribe_token))
            ->assertOk();

        $this->assertFalse($subscription->refresh()->is_active);

        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->auto()->create();

        $letter = $this->letter();

        $this->assertSame(EmailStatus::DRAFT, $letter->status);
        $this->assertStringContainsString('стоп-листе', (string) $letter->skip_reason);
    }

    #[Test]
    public function service_letters_bypass_the_stream(): void
    {
        // Пароли и восстановление доступа не проходят через правила: если фильтр
        // сломается, человек обязан суметь войти.
        $this->post(route('password.email'), ['email' => $this->client->email]);

        $this->assertSame(0, CrmEmail::query()->count());
    }
}
