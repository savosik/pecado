<?php

namespace Tests\Feature\Shortages;

use App\Models\ManagerAbsence;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\ShortageReason;
use App\Models\User;
use App\Notifications\Shortages\DailyShortageNoticeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Вечерняя сводка недоборов (17:00) и архив журнала.
 *
 * Предмет проверок — молчание в правильных случаях: письмо приходит, только если
 * за день были отмены и менеджер их не разнёс. Рассылка, которая приходит всегда,
 * перестаёт что-либо значить.
 */
class DailyShortageNoticeTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $managerProfile;

    private User $managerAccount;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.mail.features.shortage_daily_notice' => true]);
        Carbon::setTestNow(Carbon::parse('2026-08-20 17:00:00'));

        $this->managerAccount = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->managerProfile = PersonalManager::factory()->create([
            'user_id' => $this->managerAccount->id,
            'is_active' => true,
        ]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $this->managerProfile->id,
            'erp_name' => 'ООО «Ромашка»',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cancelledLine(?User $client = null, ?string $cancelledAt = null): OrderItem
    {
        $client ??= $this->client;

        $order = Order::factory()->create([
            'user_id' => $client->id,
            'erp_number' => '29УТ-011777',
        ]);

        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'cancelled' => true,
            'cancelled_at' => $cancelledAt ? Carbon::parse($cancelledAt) : now()->subHours(3),
            'quantity' => 5,
            'subtotal' => 500,
        ]);
    }

    #[Test]
    public function manager_gets_a_letter_about_todays_unmarked_shortages(): void
    {
        Notification::fake();

        $this->cancelledLine();
        $this->cancelledLine();

        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertSentTo(
            $this->managerAccount,
            DailyShortageNoticeNotification::class,
            function (DailyShortageNoticeNotification $notification) {
                $this->assertSame(2, $notification->items->count());
                $this->assertSame(1000.0, $notification->amount);
                $this->assertStringContainsString('category=none', $notification->journalUrl);

                return true;
            },
        );
    }

    #[Test]
    public function nothing_is_sent_when_the_manager_already_sorted_them_out(): void
    {
        Notification::fake();

        $line = $this->cancelledLine();
        $line->forceFill([
            'cancel_reason_id' => ShortageReason::query()->where('name', 'Отменил склад по причине недостачи')->value('id'),
            'cancel_source_user_id' => $this->managerAccount->id,
            'cancel_source_at' => now(),
        ])->save();

        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function yesterdays_shortages_do_not_trigger_a_letter(): void
    {
        Notification::fake();

        $this->cancelledLine(cancelledAt: '2026-08-19 12:00:00');

        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function archived_lines_are_not_dunned(): void
    {
        Notification::fake();

        $this->cancelledLine()->forceFill(['cancel_archived_at' => now()])->save();

        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function letter_goes_to_the_substitute_while_the_manager_is_away(): void
    {
        Notification::fake();

        $substituteAccount = User::factory()->create(['email' => 'substitute@pecado.ru']);
        $substitute = PersonalManager::factory()->create([
            'user_id' => $substituteAccount->id,
            'is_active' => true,
        ]);

        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->managerProfile->id,
            'substitute_manager_id' => $substitute->id,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
        ]);

        $this->cancelledLine();

        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertNothingSentTo($this->managerAccount);
        Notification::assertSentTo(
            $substituteAccount,
            DailyShortageNoticeNotification::class,
            function (DailyShortageNoticeNotification $notification) {
                // Замещающий должен понимать, чьи это клиенты.
                $this->assertSame($this->managerProfile->name, $notification->onBehalfOf?->name);

                return true;
            },
        );
    }

    #[Test]
    public function repeated_run_on_the_same_day_does_not_duplicate_letters(): void
    {
        Notification::fake();

        $this->cancelledLine();

        $this->artisan('shortages:daily-notice')->assertSuccessful();
        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertSentToTimes($this->managerAccount, DailyShortageNoticeNotification::class, 1);
    }

    #[Test]
    public function feature_flag_keeps_the_mailing_silent(): void
    {
        Notification::fake();
        config(['notifications.mail.features.shortage_daily_notice' => false]);

        $this->cancelledLine();

        $this->artisan('shortages:daily-notice')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function archive_command_clears_the_working_list_but_keeps_the_rows(): void
    {
        $unmarked = $this->cancelledLine();
        $marked = $this->cancelledLine();
        $marked->forceFill([
            'cancel_reason_id' => ShortageReason::query()->where('name', 'Отменил клиент после сборки заказа')->value('id'),
            'cancel_source_user_id' => $this->managerAccount->id,
            'cancel_source_at' => now(),
        ])->save();

        $this->artisan('shortages:archive')->assertSuccessful();

        $this->assertNotNull($unmarked->fresh()->cancel_archived_at);
        // Разнесённую строку архив не трогает: она уже разобрана.
        $this->assertNull($marked->fresh()->cancel_archived_at);
        // Данные на месте — сводки по товарам не теряются.
        $this->assertTrue($unmarked->fresh()->cancelled);
        $this->assertNotNull($unmarked->fresh()->cancelled_at);
    }

    #[Test]
    public function archive_respects_the_before_option(): void
    {
        $old = $this->cancelledLine(cancelledAt: '2026-07-01 10:00:00');
        $fresh = $this->cancelledLine(cancelledAt: '2026-08-20 10:00:00');

        $this->artisan('shortages:archive', ['--before' => '2026-08-01'])->assertSuccessful();

        $this->assertNotNull($old->fresh()->cancel_archived_at);
        $this->assertNull($fresh->fresh()->cancel_archived_at);
    }

    #[Test]
    public function the_letter_renders_with_the_lines_and_a_link(): void
    {
        $line = $this->cancelledLine();

        $digest = app(\App\Services\Shortage\DailyShortageDigest::class)->forDay(now());
        $group = $digest[0];

        $notification = new DailyShortageNoticeNotification(
            items: $group['items'],
            amount: $group['amount'],
            quantity: $group['quantity'],
            ordersCount: $group['orders_count'],
            dayLabel: '20.08.2026',
            journalUrl: 'https://pecado.ru/crm/shortages?source=none',
        );

        // Рендерим письмо целиком: ошибка в blade-шаблоне обязана падать в тестах,
        // а не в 17:00 у менеджера.
        $html = (string) $notification->toMail($this->managerAccount)->render();

        $this->assertStringContainsString('Недоборы за 20.08.2026', $html);
        $this->assertStringContainsString('29УТ-011777', $html);
        $this->assertStringContainsString($line->product->name, $html);
        $this->assertStringContainsString('Разнести недоборы', $html);
        $this->assertStringContainsString('crm/shortages', $html);
    }
}
