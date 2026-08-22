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
use App\Services\Crm\Mail\MailRuleEngine;
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
    public function two_independent_rules_naming_one_address_give_one_letter(): void
    {
        // Первое правило без автоотправки, второе с ней: правила независимы,
        // и достаточно одного, разрешившего отправку, — но письмо уходит одно
        // и на каждый адрес по разу.
        CrmMailRule::factory()->byTag('документы')->to(['buh@romashka.ru'])->create();
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru', 'dir@romashka.ru'])->auto()->create();

        $letter = $this->letter();

        $this->assertSame(EmailStatus::SENT, $letter->status);
        $this->assertSame(2, CrmEmailDelivery::query()->where('crm_email_id', $letter->id)->count());
        Mail::assertSent(CrmManagerMail::class, 1);
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
    public function address_added_later_receives_the_letter_and_the_old_one_does_not(): void
    {
        // Второе правило добавило адрес, пока письмо ещё не ушло. Уходит одно
        // письмо на оба адреса, а не два — но если бы первое уже ушло, повтора
        // на прежний адрес всё равно не случилось бы.
        $letter = $this->letter();
        $this->assertSame(EmailStatus::UNMATCHED, $letter->status);

        $first = CrmMailRule::factory()->byTag('документы')->to(['dir@romashka.ru'])->create();
        app(MailRuleEngine::class)->applyToOld($first);

        $ledger = app(\App\Services\Crm\Mail\MailDeliveryLedger::class);
        $letter->refresh();

        $this->assertSame(['dir@romashka.ru'], $ledger->claim($letter, $letter->to));
        // Повторный заход по тому же адресу не даёт ничего — он уже занят.
        $this->assertSame([], $ledger->claim($letter, $letter->to));

        $second = CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();
        app(MailRuleEngine::class)->applyToOld($second);

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
