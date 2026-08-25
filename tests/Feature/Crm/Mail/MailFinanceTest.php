<?php

namespace Tests\Feature\Crm\Mail;

use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Mail\Sources\FinanceScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Финансовые поводы в потоке писем.
 *
 * Главная проверка: просрочка — состояние, а не событие. Она длится месяцами,
 * и письмо про неё появляется на переходах. Иначе «Черновики» забьются за неделю,
 * а смысл писем потеряется.
 */
class MailFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);

        $this->partner = User::factory()->create([
            'email' => 'client@romashka.ru',
            'personal_manager_id' => $profile->id,
        ]);

        $this->company = Company::factory()->create([
            'user_id' => $this->partner->id,
            'name' => 'ООО Ромашка',
            'tax_id' => '7701234567',
        ]);

        config([
            'mail_stream.enabled' => true,
            // Пульт остаётся в теневом режиме и писем не шлёт: поток писем
            // от его состояния не зависит.
            'notification_pulse.enabled' => false,
        ]);
    }

    /**
     * Просрочка задаётся движениями регистра: письма считают её оттуда же,
     * откуда CRM и кабинет, — канал `balance.updated` 1С признала недостоверным.
     */
    private function setOverdue(float $amount, int $daysOverdue): void
    {
        SettlementEntry::query()->where('user_id', $this->partner->id)->delete();

        if ($amount <= 0) {
            return;
        }

        // Просроченная плановая строка — сама просрочка.
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'document_uuid' => (string) Str::uuid(),
            'document_kind' => 'shipment',
            'date' => now()->subDays($daysOverdue)->toDateString(),
            'amount' => $amount,
            'settled_amount' => 0,
            'currency_code' => 'RUB',
        ]);

        // Фактическое движение — общий долг клиента в шапке письма.
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'amount' => -$amount,
            'amount_rub' => -$amount,
            'currency_code' => 'RUB',
            'date' => now()->subDays($daysOverdue)->toDateString(),
        ]);
    }

    private function scan(): void
    {
        app(FinanceScanner::class)->scan();
    }

    #[Test]
    public function overdue_creates_one_letter_with_step_tags(): void
    {
        $this->setOverdue(150000, 65);
        $this->scan();

        $letter = CrmEmail::query()->where('origin_event', 'like', 'finance.%')->firstOrFail();

        $tags = $letter->tagList();
        $this->assertContains('оплата', $tags);
        $this->assertContains('просрочка', $tags);
        // Ступени вместо точных чисел: по подстроке числа фильтровать нельзя.
        $this->assertContains('просрочка:60+', $tags);
        $this->assertNotContains('просрочка:90+', $tags);
        $this->assertContains('сумма:100000+', $tags);
    }

    #[Test]
    public function unchanged_overdue_does_not_write_every_day(): void
    {
        $this->setOverdue(150000, 65);

        $this->scan();
        $this->scan();
        $this->scan();

        $this->assertSame(1, CrmEmail::query()->where('origin_event', 'like', 'finance.%')->count());
    }

    #[Test]
    public function crossing_a_step_is_news_again(): void
    {
        $this->setOverdue(150000, 65);
        $this->scan();

        $this->setOverdue(150000, 95);
        $this->scan();

        $letters = CrmEmail::query()->where('origin_event', 'like', 'finance.%')->get();

        $this->assertCount(2, $letters);
        $this->assertContains('просрочка:90+', $letters->last()->tagList());
    }

    #[Test]
    public function threshold_lives_in_the_rule_not_in_the_code(): void
    {
        // У одного клиента отсрочка привычна, у другого пять дней уже сигнал.
        // Поэтому письмо создаётся при любой просрочке, а кому писать —
        // решает фильтр.
        CrmMailRule::factory()->create([
            'name' => 'Просрочка от 60 дней — директору',
            'conditions' => ['all' => [
                ['field' => 'tag', 'op' => 'has_tag', 'value' => 'просрочка'],
                ['field' => 'days_overdue', 'op' => '>=', 'value' => 60],
            ]],
            'recipients' => ['dir@romashka.ru'],
        ]);

        $this->setOverdue(10000, 40);
        $this->scan();

        $letter = CrmEmail::query()->where('origin_event', 'like', 'finance.%')->firstOrFail();
        $this->assertSame([], $letter->to);

        $this->setOverdue(10000, 70);
        $this->scan();

        $caught = CrmEmail::query()->where('origin_event', 'like', 'finance.%')->latest('id')->firstOrFail();
        $this->assertSame(['dir@romashka.ru'], $caught->to);
    }
}
