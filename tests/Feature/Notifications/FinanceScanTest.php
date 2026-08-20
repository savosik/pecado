<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\ContractorBalanceOverdueDetail;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSignal;
use App\Models\User;
use App\Notifications\Pulse\PulseNotification;
use App\Services\Notifications\Pulse\FinanceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Финансовый обход: срок оплаты, просрочка, погашение.
 *
 * Ключевая проверка домена — просрочка обрабатывается как состояние,
 * а не как событие: неизменная просрочка не должна порождать письмо
 * каждый день, иначе их перестанут читать.
 */
class FinanceScanTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => [],
            'notification_pulse.domains.finance.enabled' => true,
        ]);

        Notification::fake();

        $this->partner = User::factory()->create(['email' => 'client@romashka.ru']);
        $this->company = Company::factory()->create([
            'user_id' => $this->partner->id,
            'name' => 'ООО Ромашка',
            'tax_id' => '7701234567',
        ]);

        ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'full_name' => 'Бухгалтер',
            'role' => ClientContactRole::ACCOUNTANT,
            'email' => 'buh@romashka.ru',
        ]);
    }

    private function ruleForOverdue(?array $conditions = null, string $eventKey = 'finance.*'): NotificationRule
    {
        $rule = NotificationRule::factory()->forCompany($this->company->id)->create([
            'name' => 'Просрочка — бухгалтеру',
            'event_key' => $eventKey,
            'conditions' => $conditions,
        ]);

        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE,
            'value' => ClientContactRole::ACCOUNTANT->value,
        ]);

        return $rule;
    }

    private function setOverdue(float $amount, int $daysOverdue): ContractorBalance
    {
        $balance = ContractorBalance::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'user_id' => $this->partner->id,
                'tax_id' => $this->company->tax_id,
                'current_balance' => $amount,
                'overdue_debt' => $amount,
            ],
        );

        $balance->overdueDetails()->delete();

        if ($amount > 0) {
            ContractorBalanceOverdueDetail::create([
                'contractor_balance_id' => $balance->id,
                'shipment_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'amount' => $amount,
                'due_date' => now()->subDays($daysOverdue)->toDateString(),
            ]);
        }

        return $balance->fresh('overdueDetails');
    }

    /**
     * @return array<int, string>
     */
    private function sentTo(): array
    {
        $addresses = [];

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    if ($item['notifiable'] instanceof AnonymousNotifiable) {
                        $addresses[] = $item['notifiable']->routes['mail'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function scan(): array
    {
        return app(FinanceScanner::class)->scan();
    }

    #[Test]
    #[TestDox('Возникшая просрочка порождает событие и письмо бухгалтеру')]
    public function overdue_start_is_announced(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);

        $result = $this->scan();

        $this->assertSame(1, $result['started']);
        $this->assertSame(['buh@romashka.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Неизменная просрочка второй раз письма не даёт')]
    public function unchanged_overdue_is_silent(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);

        $this->scan();
        $second = $this->scan();

        // Просрочка есть каждый день, а новость — не каждый: это и отличает
        // состояние от события
        $this->assertSame(0, $second['started']);
        $this->assertSame(0, $second['grew']);
        $this->assertCount(1, $this->sentTo());
    }

    #[Test]
    #[TestDox('Рост суммы порождает событие «просрочка выросла»')]
    public function growing_amount_is_announced(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);
        $this->scan();

        $this->setOverdue(250000, 12);
        $result = $this->scan();

        $this->assertSame(1, $result['grew']);
    }

    #[Test]
    #[TestDox('Пересечение ступени 30 дней замечается даже без роста суммы')]
    public function crossing_step_is_announced(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 25);
        $this->scan();

        $this->setOverdue(150000, 31);
        $result = $this->scan();

        $this->assertSame(1, $result['grew']);

        $signal = NotificationSignal::where('event_key', 'finance.overdue_grew')->latest('id')->sole();
        $this->assertSame('30', $signal->data['crossed_step']);
        $this->assertContains('просрочка:30+', $signal->tags);
    }

    #[Test]
    #[TestDox('Погашение просрочки порождает отдельное событие')]
    public function clearing_is_announced(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);
        $this->scan();

        $this->setOverdue(0, 0);
        $result = $this->scan();

        $this->assertSame(1, $result['cleared']);

        $signal = NotificationSignal::where('event_key', 'finance.overdue_cleared')->sole();
        $this->assertSame(150000.0, (float) $signal->data['was_amount']);
    }

    #[Test]
    #[TestDox('После погашения новая просрочка снова считается возникшей')]
    public function overdue_restarts_after_clearing(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);
        $this->scan();
        $this->setOverdue(0, 0);
        $this->scan();

        $this->setOverdue(50000, 3);
        $result = $this->scan();

        $this->assertSame(1, $result['started']);
    }

    #[Test]
    #[TestDox('Порог задаётся условием правила, а не кодом')]
    public function threshold_lives_in_rule(): void
    {
        // У этого клиента отсрочка привычная — пишем только с 60 дней
        $this->ruleForOverdue(['field' => 'days_overdue', 'op' => '>=', 'value' => 60]);

        $this->setOverdue(150000, 20);
        $this->scan();

        Notification::assertNothingSent();

        $this->setOverdue(150000, 65);
        $this->scan();

        $this->assertSame(['buh@romashka.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Домен выключен — обход молчит')]
    public function disabled_domain_is_silent(): void
    {
        config(['notification_pulse.domains.finance.enabled' => false]);

        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);
        $this->scan();

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Пробный обход ничего не отправляет')]
    public function dry_run_sends_nothing(): void
    {
        $this->ruleForOverdue();
        $this->setOverdue(150000, 10);

        app(FinanceScanner::class)->scan(3, dryRun: true);

        Notification::assertNothingSent();
    }
}
