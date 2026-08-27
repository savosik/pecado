<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\CrmEmail;
use App\Models\NotificationPreference;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Склейка партии: восемь писем за две минуты — одно событие.
 *
 * Боевой повод: 24 августа 1С разом сменила статус у восьми заказов Гущиной,
 * и каждый выслал своё письмо. 25 августа поток собрал семь писем
 * «Готов к закрытию» за две секунды.
 */
class BatchCoalescingTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\EnablesClientNotifications;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $manager = User::factory()->create();
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config([
            'mail_stream.enabled' => true,
            'mail_stream.autosend' => false,
            'mail_stream.batch_seconds' => 180,
        ]);

        $this->enableNotificationsFor($this->client);
    }

    private function statusLetter(string $orderNumber, string $status = 'shipping', ?User $client = null): ?CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'orders.status_changed',
            clientUserId: ($client ?? $this->client)->id,
            data: [
                'order_id' => crc32($orderNumber),
                'order_number' => $orderNumber,
                'status' => $status,
                'status_label' => 'В процессе отгрузки',
            ],
        ));
    }

    #[Test]
    public function партия_из_восьми_заказов_даёт_одно_письмо(): void
    {
        foreach (['1001', '1002', '1003', '1004', '1005', '1006', '1007', '1008'] as $number) {
            $this->statusLetter($number);
        }

        $this->assertSame(1, CrmEmail::query()->count());

        // И одна задача на отправку, а не восемь.
        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    #[Test]
    public function поводы_разных_типов_не_склеиваются(): void
    {
        $this->statusLetter('1001');

        app(MailStream::class)->capture(new Occasion(
            key: 'orders.created',
            clientUserId: $this->client->id,
            data: ['order_id' => 1, 'order_number' => '1009'],
        ));

        $this->assertSame(2, CrmEmail::query()->count());
    }

    #[Test]
    public function поводы_разных_партнёров_не_склеиваются(): void
    {
        $other = User::factory()->create([
            'personal_manager_id' => $this->client->personal_manager_id,
            'email' => 'other@example.com',
        ]);
        $this->enableNotificationsFor($other);

        $this->statusLetter('1001');
        $this->statusLetter('1002', client: $other);

        $this->assertSame(2, CrmEmail::query()->count());
    }

    #[Test]
    public function событие_вне_окна_даёт_новое_письмо(): void
    {
        $first = $this->statusLetter('1001');

        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->statusLetter('1002');

        $this->assertSame(2, CrmEmail::query()->count());
    }

    #[Test]
    public function отправленное_письмо_не_дописывается(): void
    {
        $first = $this->statusLetter('1001');
        $first->forceFill(['status' => \App\Enums\Crm\EmailStatus::SENT->value])->save();

        $this->statusLetter('1002');

        $this->assertSame(2, CrmEmail::query()->count());
    }

    #[Test]
    public function невостребованный_повод_письма_не_создаёт(): void
    {
        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $this->client->id, 'occasion_key' => 'orders.status_changed'],
            [
                'is_enabled' => false,
            ]);

        $this->statusLetter('1001');

        // Раньше такие письма копились в «Мимо фильтров» и выглядели
        // недоработкой, хотя были нормой.
        $this->assertSame(0, CrmEmail::query()->count());
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    #[Test]
    public function выключенный_рубильник_адресует_но_не_отправляет(): void
    {
        // Механизм приезжает на прод раньше, чем кто-то посмотрел на умолчания
        // глазами. Пока рубильник выключен, видно, кому что ушло бы,
        // и не уходит ничего.
        config([
            'mail_stream.autosend' => true,
            'mail_stream.notifications_live' => false,
            'notifications.mail.features.crm_outbound' => true,
        ]);

        // Очередь в этом классе подменена, поэтому зовём отправку напрямую —
        // проверяем именно её отказ, а не постановку задачи.
        $letter = $this->statusLetter('1001');
        app(\App\Services\Notifications\NotificationDispatcher::class)->send($letter);
        $letter->refresh();

        $this->assertContains($this->client->email, (array) $letter->to);
        $this->assertNotSame(\App\Enums\Crm\EmailStatus::SENT, $letter->status);
        $this->assertStringContainsString('MAIL_NOTIFICATIONS_LIVE', (string) $letter->skip_reason);
    }

    #[Test]
    public function внутренний_статус_письма_не_создаёт(): void
    {
        $this->statusLetter('1001', 'ready_for_closure');

        $this->assertSame(0, CrmEmail::query()->count());
    }
}
