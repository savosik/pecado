<?php

namespace Tests\Feature\Mail;

use App\Enums\UserKind;
use App\Events\OrdersPlaced;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\SentEmail;
use App\Models\User;
use App\Notifications\Orders\NewOrderForManagerNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Журнал исходящих писем: чем закрывается вопрос «кому это ушло».
 *
 * Проверяем не факт отправки (это дело тестов самих уведомлений), а то, что
 * после отправки в журнале лежит запись с правильным получателем и правильным
 * клиентом — включая случай, когда получатель клиентом не является.
 */
class SentEmailJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'notifications.mail.journal_enabled' => true,
            'notifications.mail.features.manager_new_order' => true,
            'notifications.mail.features.order_created' => false,
        ]);
    }

    #[Test]
    public function manager_letter_about_order_is_filed_under_the_client(): void
    {
        $manager = User::factory()->create([
            'email' => 'anna@pecado.ru',
            'user_kind' => UserKind::STAFF,
        ]);
        $profile = PersonalManager::factory()->create([
            'user_id' => $manager->id,
            'email' => 'anna@pecado.ru',
        ]);

        $client = User::factory()->create([
            'user_kind' => UserKind::CLIENT,
            'personal_manager_id' => $profile->id,
        ]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        $record = SentEmail::sole();

        $this->assertSame('anna@pecado.ru', $record->recipient);
        $this->assertSame($manager->id, $record->recipient_user_id);
        // Письмо адресовано менеджеру, но событие принадлежит клиенту —
        // иначе оно не попало бы в его карточку, ради чего журнал и заведён.
        $this->assertSame($client->id, $record->client_user_id);
        $this->assertSame(NewOrderForManagerNotification::class, $record->source);
        $this->assertStringContainsString('Новый заказ', $record->subject);
    }

    #[Test]
    public function letter_to_the_client_is_filed_under_the_client_without_tagging(): void
    {
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'user_kind' => UserKind::CLIENT,
        ]);

        Mail::raw('тело', fn ($message) => $message->to('client@example.com')->subject('Привет'));

        $record = SentEmail::sole();

        $this->assertSame($client->id, $record->client_user_id);
        $this->assertSame($client->id, $record->recipient_user_id);
    }

    #[Test]
    public function letter_to_a_staff_member_is_not_filed_under_a_client(): void
    {
        $staff = User::factory()->create([
            'email' => 'ops@pecado.ru',
            'user_kind' => UserKind::STAFF,
        ]);

        Mail::raw('тело', fn ($message) => $message->to('ops@pecado.ru')->subject('Задача'));

        $record = SentEmail::sole();

        $this->assertSame($staff->id, $record->recipient_user_id);
        // Сотрудник — не партнёр: письмо коллеге не событие в чьей-то карточке
        $this->assertNull($record->client_user_id);
    }

    #[Test]
    public function unknown_recipient_is_still_recorded(): void
    {
        Mail::raw('тело', fn ($message) => $message->to('someone@example.org')->subject('Ответ на вопрос'));

        $record = SentEmail::sole();

        $this->assertSame('someone@example.org', $record->recipient);
        $this->assertNull($record->recipient_user_id);
        $this->assertNull($record->client_user_id);
    }

    #[Test]
    public function every_recipient_gets_its_own_row(): void
    {
        Mail::raw('тело', fn ($message) => $message
            ->to(['one@example.org', 'two@example.org'])
            ->subject('Двоим'));

        $this->assertSame(2, SentEmail::count());
        $this->assertEqualsCanonicalizing(
            ['one@example.org', 'two@example.org'],
            SentEmail::pluck('recipient')->all(),
        );
    }

    #[Test]
    public function managers_letter_is_recognised_by_its_own_header(): void
    {
        // Mailable не оставляет в событии следа своего класса, поэтому письмо
        // менеджера узнаётся по заголовку. Если узнавание сломается, письмо
        // молча задвоится в ленте партнёра — то есть тихо, но заметно менеджеру.
        $manager = User::factory()->create(['user_kind' => UserKind::STAFF]);
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $client = User::factory()->create([
            'email' => 'client@example.com',
            'user_kind' => UserKind::CLIENT,
            'personal_manager_id' => $profile->id,
        ]);

        $email = \App\Models\CrmEmail::factory()->by($manager)->on($client)->create([
            'to' => ['client@example.com'],
        ]);

        \Illuminate\Support\Facades\Mail::to($email->to)->send(new \App\Mail\CrmManagerMail($email));

        $this->assertSame(\App\Mail\CrmManagerMail::class, SentEmail::sole()->source);
    }

    #[Test]
    public function journal_can_be_switched_off(): void
    {
        config(['notifications.mail.journal_enabled' => false]);

        Mail::raw('тело', fn ($message) => $message->to('client@example.com')->subject('Привет'));

        $this->assertSame(0, SentEmail::count());
    }

    #[Test]
    public function faked_notifications_do_not_reach_the_journal(): void
    {
        // Страховка от ложного зелёного в других тестах: Notification::fake()
        // не доводит письмо до почты, значит и записи быть не должно.
        Notification::fake();

        $client = User::factory()->create(['user_kind' => UserKind::CLIENT]);
        $order = Order::factory()->create(['user_id' => $client->id]);

        OrdersPlaced::dispatch(collect([$order]));

        $this->assertSame(0, SentEmail::count());
    }

    #[Test]
    public function old_records_are_pruned_by_retention(): void
    {
        config(['notifications.mail.journal_retention_days' => 30]);

        $old = SentEmail::create([
            'recipient' => 'old@example.org',
            'subject' => 'Старое',
            'sent_at' => now()->subDays(31),
        ]);
        $fresh = SentEmail::create([
            'recipient' => 'fresh@example.org',
            'subject' => 'Свежее',
            'sent_at' => now()->subDays(29),
        ]);

        $this->artisan('model:prune', ['--model' => [SentEmail::class]])->assertSuccessful();

        $this->assertDatabaseMissing('sent_emails', ['id' => $old->id]);
        $this->assertDatabaseHas('sent_emails', ['id' => $fresh->id]);
    }

    #[Test]
    public function zero_retention_keeps_everything(): void
    {
        config(['notifications.mail.journal_retention_days' => 0]);

        $record = SentEmail::create([
            'recipient' => 'ancient@example.org',
            'sent_at' => now()->subYears(3),
        ]);

        $this->artisan('model:prune', ['--model' => [SentEmail::class]])->assertSuccessful();

        $this->assertDatabaseHas('sent_emails', ['id' => $record->id]);
    }
}
