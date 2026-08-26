<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Jobs\SendCrmEmailJob;
use App\Mail\CrmManagerMail;
use App\Models\CrmEmail;
use App\Models\CrmEmailDelivery;
use App\Models\CrmMailRule;
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
 * Гарантия «одно письмо — один адрес — один раз».
 *
 * Правила-фильтры независимы и знать друг о друге не должны: два фильтра
 * вполне могут поймать одно письмо и назвать один и тот же адрес. Ненормально
 * не это, а если клиент получит два одинаковых письма — и разбираться с этим
 * должно одно место, а не каждое правило по отдельности.
 */
class MailDeliveryLedgerTest extends TestCase
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
            'mail_stream.notifications_live' => true,
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
    public function два_одинаковых_адресата_дают_одно_письмо(): void
    {
        // Один и тот же адрес назван дважды — прямо и через роль контакта.
        // Письмо обязано уйти на него ровно один раз.
        config(['mail_stream.autosend' => true, 'mail_stream.notifications_live' => true]);

        \App\Models\NotificationPreference::query()->create([
            'user_id' => $this->client->id,
            'occasion_key' => 'documents.published',
            'is_enabled' => true,
            'destinations' => [
                ['type' => 'email', 'email' => 'buh@romashka.ru'],
                ['type' => 'email', 'email' => 'BUH@romashka.ru'],
                ['type' => 'email', 'email' => 'dir@romashka.ru'],
            ],
        ]);

        $letter = $this->letter();

        $this->assertSame(EmailStatus::SENT, $letter->status);
        $this->assertSame(2, CrmEmailDelivery::query()->where('crm_email_id', $letter->id)->count());

        // Письмо уходит каждому адресату отдельным экземпляром (так различаются
        // открытия), поэтому экземпляров два — по числу адресов, а не по числу
        // правил. Суть проверки в другом: buh@ назван двумя правилами и обязан
        // получить письмо ровно один раз.
        Mail::assertSent(CrmManagerMail::class, 2);

        $addressed = [];

        Mail::assertSent(CrmManagerMail::class, function (CrmManagerMail $mail) use (&$addressed): bool {
            $addressed[] = $mail->delivery?->recipient;

            return true;
        });

        sort($addressed);

        $this->assertSame(['buh@romashka.ru', 'dir@romashka.ru'], $addressed);
    }

    #[Test]
    public function repeated_job_run_does_not_send_a_second_time(): void
    {
        // Задание очереди повторяется при сбое сети: если письмо успело уйти,
        // а результат записаться не успел, клиент не должен получить его дважды.
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->auto()->create();

        $letter = $this->letter();
        $this->assertSame(EmailStatus::SENT, $letter->status);

        // Повтор задания после сбоя: письмо уже ушло, реестр это помнит.
        $letter->forceFill(['status' => EmailStatus::QUEUED->value])->save();

        (new SendCrmEmailJob($letter))->handle(app(\App\Services\Crm\Mail\MailDeliveryLedger::class));

        Mail::assertSent(CrmManagerMail::class, 1);
        $this->assertSame(1, CrmEmailDelivery::query()->where('crm_email_id', $letter->id)->count());
    }

    #[Test]
    public function адрес_добавленный_позже_получает_письмо_а_прежний_нет(): void
    {
        // Настройку поменяли, пока письмо ещё не ушло. Прежний адрес повтора
        // не получает, новый — получает.
        config(['mail_stream.autosend' => false]);

        \App\Models\NotificationPreference::query()->create([
            'user_id' => $this->client->id,
            'occasion_key' => 'documents.published',
            'is_enabled' => true,
            'destinations' => [['type' => 'email', 'email' => 'dir@romashka.ru']],
        ]);

        $letter = $this->letter();
        $ledger = app(\App\Services\Crm\Mail\MailDeliveryLedger::class);

        $this->assertSame(['dir@romashka.ru'], $ledger->claim($letter, $letter->to));
        // Повторный заход по тому же адресу не даёт ничего — он уже занят.
        $this->assertSame([], $ledger->claim($letter, $letter->to));

        $letter->forceFill(['to' => ['dir@romashka.ru', 'buh@romashka.ru']])->save();

        $this->assertSame(['buh@romashka.ru'], $ledger->claim($letter->refresh(), $letter->to));
    }

    #[Test]
    public function address_case_does_not_open_a_second_door(): void
    {
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'to' => ['Buh@Romashka.RU'],
        ]);

        $ledger = app(\App\Services\Crm\Mail\MailDeliveryLedger::class);

        $this->assertSame(['Buh@Romashka.RU'], $ledger->claim($letter, ['Buh@Romashka.RU']));
        $this->assertSame([], $ledger->claim($letter, ['buh@romashka.ru']));
    }

    #[Test]
    public function refused_by_the_server_letter_can_be_sent_again(): void
    {
        // Сбой SMTP означает, что письмо не уходило. Если оставить адрес
        // занятым, повторная попытка объявит письмо отправленным, не отправив
        // ничего, — а менеджер узнает об этом от клиента.
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'to' => ['buh@romashka.ru'],
            'status' => EmailStatus::QUEUED,
        ]);

        $ledger = app(\App\Services\Crm\Mail\MailDeliveryLedger::class);

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP недоступен'));

        try {
            (new SendCrmEmailJob($letter))->handle($ledger);
            $this->fail('Ожидали, что сбой транспорта прорастёт наружу');
        } catch (\Throwable) {
            // ожидаемо
        }

        $this->assertSame(0, CrmEmailDelivery::query()->where('crm_email_id', $letter->id)->count());
    }

    #[Test]
    public function nothing_left_to_send_is_success_not_failure(): void
    {
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create([
            'to' => ['buh@romashka.ru'],
            'status' => EmailStatus::QUEUED,
        ]);

        $ledger = app(\App\Services\Crm\Mail\MailDeliveryLedger::class);
        $ledger->claim($letter, $letter->to);

        (new SendCrmEmailJob($letter))->handle($ledger);

        Mail::assertNothingSent();
        $this->assertSame(EmailStatus::SENT, $letter->refresh()->status);
    }
}
