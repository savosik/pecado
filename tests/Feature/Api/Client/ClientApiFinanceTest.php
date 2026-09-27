<?php

namespace Tests\Feature\Api\Client;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\CrmEmail;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Crm\Finance\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiFinanceTest extends ClientApiTestCase
{
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 12:00'));
        Queue::fake();
        config([
            'notifications.mail.features.crm_outbound' => true,
            'cabinet.finance_enabled' => false,
            'cabinet.finance_pilot_user_ids' => '',
            'debt.enabled' => false,
        ]);

        $manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $card = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $this->client->update(['personal_manager_id' => $card->id]);
        $this->company->update([
            'name' => 'ООО Ромашка',
            'legal_name' => 'Общество с ограниченной ответственностью «Ромашка»',
            'tax_id' => '7701234567',
            'tax_code' => '770101001',
        ]);
        CompanyBankAccount::factory()->create([
            'company_id' => $this->company->id,
            'account_number' => '40702810900000001111',
            'bank_bik' => '044525225',
            'is_primary' => true,
        ]);
        $this->organization = Organization::factory()->create([
            'name' => 'Пекадо',
            'legal_name' => 'ООО «Пекадо»',
            'tax_id' => '7709876543',
            'tax_code' => '770901001',
            'bank_name' => 'ПАО Сбербанк',
            'bank_bik' => '044525225',
            'correspondent_account' => '30101810400000000225',
            'account_number' => '40702810100000009999',
            'is_stub' => false,
        ]);

        $this->plan(40000, '2026-07-20', '29УТ-000001', '2026-07-01');
        $this->plan(25000, '2026-09-10', '29УТ-000002', '2026-08-25');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function plan(float $amount, string $due, string $number, string $date): void
    {
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'document_kind' => 'shipment',
            'document_number' => $number,
            'document_date' => $date,
            'date' => $due,
            'amount' => $amount,
            'amount_rub' => $amount,
            'settled_amount' => 0,
        ]);
    }

    private function enableFinance(): void
    {
        config(['cabinet.finance_pilot_user_ids' => (string) $this->client->id]);
    }

    #[Test]
    #[TestDox('Финансы закрыты — 403 finance_unavailable; пилотному клиенту открыты баланс, календарь и платежи')]
    public function finance_is_gated_by_pilot(): void
    {
        foreach (['/finance/balance', '/finance/payments', '/finance/calendar', '/finance/reconciliation', '/payment-orders/options'] as $uri) {
            $this->api('GET', $uri)->assertStatus(403);
        }
        $this->api('GET', '/finance/balance')->assertJsonPath('errors.0.code', 'finance_unavailable');
        $this->api('GET', '/payment-orders/options')->assertJsonPath('errors.0.code', 'payment_orders_unavailable');

        $this->enableFinance();
        $mine = Payment::factory()->create(['user_id' => $this->client->id, 'company_id' => $this->company->id, 'organization_id' => $this->organization->id, 'currency_code' => 'RUB', 'amount' => 1500]);
        Payment::factory()->create(['user_id' => User::factory()->create()->id, 'company_id' => Company::factory()->create()->id, 'organization_id' => $this->organization->id]);

        $this->api('GET', '/finance/balance')->assertOk()->assertJsonStructure(['data' => ['summary', 'by_organization', 'companies']]);
        $this->api('GET', '/finance/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 1500);
        $this->api('GET', '/finance/payments/'.$mine->id)->assertOk()->assertJsonPath('data.id', $mine->id)->assertJsonStructure(['data' => ['purpose', 'company']]);
        $this->api('GET', '/finance/payments/'.Payment::where('user_id', '!=', $this->client->id)->value('id'))->assertStatus(404);
        $this->api('GET', '/finance/calendar?month=2026-09')->assertOk()->assertJsonPath('data.month', '2026-09')->assertJsonStructure(['data' => ['entries', 'overdue', 'summary']]);
        $this->api('GET', '/me')->assertJsonPath('data.features.finance', true)->assertJsonPath('data.features.payment_orders', true);
    }

    #[Test]
    #[TestDox('Акт сверки через API совпадает с сервисом сверки за тот же период')]
    public function reconciliation_matches_the_service(): void
    {
        $this->enableFinance();

        $expected = app(ReconciliationService::class)->act(
            client: $this->client,
            organizationId: $this->organization->id,
            from: '2026-07-01',
            to: '2026-08-31',
            companyId: $this->company->id,
        );

        $actual = $this->api('GET', '/finance/reconciliation?'.http_build_query([
            'organization_id' => $this->organization->id,
            'company_id' => $this->company->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-31',
        ]))->assertOk()->json('data');

        $this->assertEquals(json_decode(json_encode($expected), true), $actual);
    }

    #[Test]
    #[TestDox('Платёжка: options, preview с QR, подписанная ссылка на PDF и txt, идемпотентная отправка')]
    public function payment_orders(): void
    {
        $this->enableFinance();

        $options = $this->api('GET', '/payment-orders/options')->assertOk()->json('data');
        $this->assertNotEmpty($options['scenarios']);

        $params = ['company_id' => $this->company->id, 'organization_id' => $this->organization->id, 'scenario' => 'overdue'];

        $preview = $this->api('GET', '/payment-orders/preview?'.http_build_query($params))->assertOk();
        $this->assertSame(40000.0, (float) $preview->json('data.amount'));
        $this->assertStringStartsWith('data:image', $preview->json('data.qr_data_uri'));

        $this->api('GET', '/payment-orders/preview?'.http_build_query(array_merge($params, ['scenario' => 'nope'])))->assertStatus(422);

        $pdf = $this->api('GET', '/payment-orders/link?'.http_build_query($params))->assertOk();
        $this->assertStringEndsWith('.pdf', $pdf->json('data.filename'));
        $this->get($pdf->json('data.download_url'))->assertOk()->assertHeader('content-type', 'application/pdf');

        $txt = $this->api('GET', '/payment-orders/link?'.http_build_query($params + ['format' => 'txt']))->assertOk()->json('data.download_url');
        $this->get($txt)->assertOk()->assertHeader('content-type', 'text/plain; charset=windows-1251');

        $this->api('POST', '/payment-orders/send', $params + ['email' => 'buh@romashka.ru', 'save_contact' => true], ['Idempotency-Key' => 'po-1'])
            ->assertStatus(201)->assertJsonPath('data.sent', true);
        $this->api('POST', '/payment-orders/send', $params + ['email' => 'buh@romashka.ru', 'save_contact' => true], ['Idempotency-Key' => 'po-1'])
            ->assertStatus(201)->assertJsonPath('meta.idempotent_replay', true);

        $this->assertSame(1, CrmEmail::count());

        // Без пилота подписанная ссылка тоже закрыта
        config(['cabinet.finance_pilot_user_ids' => '']);
        $this->get($pdf->json('data.download_url'))->assertStatus(404);
    }
}
