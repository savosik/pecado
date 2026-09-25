<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Notifications\Assistant\AssistantAvailabilityNotification;
use App\Services\Assistant\AssistantAvailability;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

/**
 * РОП узнаёт о пропаже помощника письмом — одно на смену состояния, не чаще раза в час.
 */
class AvailabilityNotificationTest extends AssistantTestCase
{
    #[Test]
    public function роп_получает_письмо_о_пропаже_и_о_возврате_без_дублей(): void
    {
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $head = User::factory()->create(['email' => 'rop@pecado.ru']);
        $head->assignRole('sales-head');
        $manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $manager->assignRole('sales-manager');

        $availability = app(AssistantAvailability::class);

        $availability->markUnavailable('billing: credit balance is too low');
        $availability->markUnavailable('billing: ещё раз');

        Notification::assertSentTo($head, AssistantAvailabilityNotification::class, fn ($n) => $n->available === false);
        Notification::assertCount(1);
        Notification::assertNotSentTo($manager, AssistantAvailabilityNotification::class);

        $availability->markAvailable();
        Notification::assertSentTo($head, AssistantAvailabilityNotification::class, fn ($n) => $n->available === true);
        Notification::assertCount(2);
    }

    #[Test]
    public function письмо_о_пропаже_объясняет_причину_по_русски(): void
    {
        $head = User::factory()->create(['email' => 'rop@pecado.ru']);

        $mail = (new AssistantAvailabilityNotification(false, 'billing: credit balance is too low'))->toMail($head);
        $rendered = implode(' ', array_map(fn ($line) => (string) $line, $mail->introLines));

        $this->assertStringContainsString('баланс', $rendered);
        $this->assertStringContainsString('вернётся сам', $rendered);
        $this->assertSame('Помощник клиента пропал с сайта — Pecado.ru', $mail->subject);
    }
}
