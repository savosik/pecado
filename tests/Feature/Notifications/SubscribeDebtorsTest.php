<?php

namespace Tests\Feature\Notifications;

use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\NotificationPreference;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Массовая подписка должников на понедельничную сводку актов.
 */
class SubscribeDebtorsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'documents.reconciliation_weekly';

    private function debtor(string $kind = 'shipment', float $unpaid = 1000): User
    {
        $client = User::factory()->create(['email' => 'debtor'.fake()->unique()->numberBetween(1, 9999).'@example.com']);
        $company = Company::factory()->create(['user_id' => $client->id]);

        SettlementEntry::factory()->create([
            'user_id' => $client->id,
            'company_id' => $company->id,
            'nature' => 'plan',
            'document_kind' => $kind,
            'amount' => $unpaid,
            'settled_amount' => 0,
            'date' => now()->subDays(5),
        ]);

        return $client;
    }

    #[Test]
    public function должник_подписывается_на_почту_аккаунта(): void
    {
        $client = $this->debtor();

        $this->artisan('notifications:subscribe-debtors')->assertSuccessful();

        $row = NotificationPreference::query()
            ->where('user_id', $client->id)->where('occasion_key', self::KEY)->firstOrFail();

        $this->assertTrue($row->is_enabled);
        $this->assertSame('login', $row->destinations[0]['type']);
    }

    #[Test]
    public function при_наличии_бухгалтера_акты_идут_ему(): void
    {
        // Ровно ради этого справочник контактов и заводился.
        $client = $this->debtor();
        $contact = Contact::factory()->create(['client_user_id' => $client->id, 'email' => 'buh@example.com']);
        ContactLink::query()->create([
            'contact_id' => $contact->id,
            'subject_type' => User::class,
            'subject_id' => $client->id,
            'role' => ContactRole::ACCOUNTANT->value,
            'client_user_id' => $client->id,
        ]);

        $this->artisan('notifications:subscribe-debtors')->assertSuccessful();

        $row = NotificationPreference::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame('contact', $row->destinations[0]['type']);
        $this->assertSame($contact->id, $row->destinations[0]['contact_id']);
    }

    #[Test]
    public function план_по_заказу_долгом_не_считается(): void
    {
        // Заказ — это план, а не долг: долг создаёт отгрузка. Иначе
        // просроченный план заказа числился бы долгом навсегда.
        $this->debtor('order');

        $this->artisan('notifications:subscribe-debtors')->assertSuccessful();

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function погашенный_долг_не_подписывает(): void
    {
        $client = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $client->id]);
        SettlementEntry::factory()->create([
            'user_id' => $client->id,
            'company_id' => $company->id,
            'nature' => 'plan',
            'document_kind' => 'shipment',
            'amount' => 500,
            'settled_amount' => 500,
            'date' => now()->subDays(5),
        ]);

        $this->artisan('notifications:subscribe-debtors')->assertSuccessful();

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function настроенное_руками_не_перетирается(): void
    {
        $client = $this->debtor();
        NotificationPreference::query()->create([
            'user_id' => $client->id,
            'occasion_key' => self::KEY,
            'is_enabled' => false,
        ]);

        $this->artisan('notifications:subscribe-debtors')->assertSuccessful();

        // Чьё-то решение важнее массовой правки.
        $this->assertFalse(
            NotificationPreference::query()->where('user_id', $client->id)->firstOrFail()->is_enabled,
        );
    }

    #[Test]
    public function сухой_прогон_ничего_не_пишет(): void
    {
        $this->debtor();

        $this->artisan('notifications:subscribe-debtors', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, NotificationPreference::query()->count());
    }
}
