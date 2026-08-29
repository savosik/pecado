<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Models\CrmEmail;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Payments\PaymentOrderService;
use App\Support\Crm\CrmAttachments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Платёжка «бери и плати» (pay-01): сценарии суммы, назначение, QR по ГОСТ,
 * файл клиент-банка, PDF, отправка бухгалтеру с сохранением адреса.
 */
class PaymentOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Company $company;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-29 12:00'));
        config([
            'debt.enabled' => true,
            'debt.mode' => 'live',
            'debt.live_actions' => 'cabinet',
            'notifications.mail.features.crm_outbound' => true,
        ]);

        $manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $card = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
        $this->company = Company::factory()->create([
            'user_id' => $this->client->id,
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

    #[Test]
    public function overdue_scenario_takes_only_overdue_lines_and_writes_gost_qr(): void
    {
        $order = $this->service()->build($this->client, $this->company->id, $this->organization->id, 'overdue');

        $this->assertSame(40000.0, $order->amount);
        $this->assertCount(1, $order->documents);
        $this->assertStringContainsString('№ 29УТ-000001 от 01.07.2026', $order->purpose);
        $this->assertStringContainsString('НДС — по счёту', $order->purpose);

        $qr = $order->qrPayload();
        $this->assertStringStartsWith('ST00012|Name=ООО «Пекадо»|PersonalAcc=40702810100000009999|BankName=ПАО Сбербанк|BIC=044525225|CorrespAcc=30101810400000000225', $qr);
        $this->assertStringContainsString('|Sum=4000000|', $qr);
        $this->assertStringContainsString('PayeeINN=7709876543', $qr);
    }

    #[Test]
    public function all_scenario_uses_ledger_balance_and_document_scenario_single_line(): void
    {
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'amount' => -65000,
            'amount_rub' => -65000,
        ]);

        $all = $this->service()->build($this->client, $this->company->id, $this->organization->id, 'all');
        $this->assertSame(65000.0, $all->amount);
        $this->assertCount(2, $all->documents);

        $line = SettlementEntry::query()->where('document_number', '29УТ-000002')->firstOrFail();
        $single = $this->service()->build($this->client, $this->company->id, $this->organization->id, 'document', $line->id);
        $this->assertSame(25000.0, $single->amount);
        $this->assertStringContainsString('Оплата по документу № 29УТ-000002', $single->purpose);
    }

    #[Test]
    public function purpose_references_signed_contract_of_the_pair(): void
    {
        $category = \App\Models\ContractCategory::factory()->create();
        \App\Models\Contract::factory()->create([
            'category_id' => $category->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            // Как в реестре после импорта: номер уже со знаком номера.
            'number' => '№ П-17/2026',
            'date' => '2026-02-10',
            'valid_until' => null,
            'status' => \App\Enums\Crm\ContractStatus::SIGNED->value,
        ]);
        // Расторгнутый договор в назначение не попадает.
        \App\Models\Contract::factory()->create([
            'category_id' => $category->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'number' => 'СТАРЫЙ',
            'date' => '2025-01-01',
            'status' => \App\Enums\Crm\ContractStatus::TERMINATED->value,
        ]);

        $order = $this->service()->build($this->client, $this->company->id, $this->organization->id, 'overdue');

        $this->assertSame('П-17/2026', $order->contract['number']);
        $this->assertStringStartsWith('Оплата по договору № П-17/2026 от 10.02.2026 за товар по просроченным документам № 29УТ-000001 от 01.07.2026.', $order->purpose);
        $this->assertStringContainsString('Сумма 40 000,00 руб.', $order->purpose);
        $this->assertStringContainsString('Purpose=Оплата по договору № П-17/2026', $order->qrPayload());

        $custom = $this->service()->build($this->client, $this->company->id, $this->organization->id, 'custom', null, 5000);
        $this->assertStringStartsWith('Оплата по договору № П-17/2026 от 10.02.2026 (предоплата).', $custom->purpose);
    }

    #[Test]
    public function client_bank_exchange_is_cp1251_with_payee_requisites(): void
    {
        $order = $this->service()->build($this->client, $this->company->id, $this->organization->id, 'overdue');
        $text = mb_convert_encoding($this->service()->clientBankExchange($order), 'UTF-8', 'Windows-1251');

        $this->assertStringStartsWith("1CClientBankExchange\r\nВерсияФормата=1.03", $text);
        $this->assertStringContainsString('ПолучательРасчСчет=40702810100000009999', $text);
        $this->assertStringContainsString('ПлательщикИНН=7701234567', $text);
        $this->assertStringContainsString('Сумма=40000.00', $text);
        $this->assertStringContainsString('КонецФайла', $text);
    }

    #[Test]
    public function pdf_is_rendered_and_downloadable_from_cabinet(): void
    {
        $this->actingAs($this->client)
            ->get('/cabinet/payment-orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('User/Cabinet/PaymentOrders/Index')->has('options.pairs', 1));

        $response = $this->actingAs($this->client)->get('/cabinet/payment-orders/download?'.http_build_query([
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'scenario' => 'overdue',
            'format' => 'pdf',
        ]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function sending_creates_letter_with_two_attachments_and_remembers_accountant(): void
    {
        Queue::fake();

        $this->actingAs($this->client)
            ->post('/cabinet/payment-orders/send', [
                'company_id' => $this->company->id,
                'organization_id' => $this->organization->id,
                'scenario' => 'overdue',
                'email' => 'buh@romashka.ru',
                'save_contact' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $letter = CrmEmail::query()->sole();
        $this->assertSame(['buh@romashka.ru'], $letter->to);
        $this->assertStringContainsString('Платёжное поручение на 40 000,00 ₽', $letter->subject);
        $this->assertCount(2, $letter->getMedia(CrmAttachments::COLLECTION));
        $this->assertSame('manager@pecado.ru', $letter->reply_to);

        $contact = Contact::query()->where('email', 'buh@romashka.ru')->sole();
        $this->assertSame($this->client->id, $contact->client_user_id);
        $this->assertSame('accountant', $contact->links()->sole()->role->value);
    }

    #[Test]
    public function cabinet_is_closed_when_client_sees_no_money(): void
    {
        config(['debt.mode' => 'shadow', 'cabinet.finance_enabled' => false]);

        $this->actingAs($this->client)->get('/cabinet/payment-orders')->assertNotFound();
        $this->actingAs($this->client)->getJson('/cabinet/payment-orders/options')->assertNotFound();
    }

    /**
     * Диалог платёжки из календаря оплат грузит те же пары и документы JSON-ом,
     * а строку календаря находит по id записи регистра — это ключ сценария «документ».
     */
    #[Test]
    public function options_are_served_as_json_for_the_calendar_dialog(): void
    {
        $overdue = SettlementEntry::query()->where('document_number', '29УТ-000001')->firstOrFail();

        $this->actingAs($this->client)
            ->getJson('/cabinet/payment-orders/options')
            ->assertOk()
            ->assertJsonPath('pairs.0.key', $this->company->id.':'.$this->organization->id)
            ->assertJsonPath('pairs.0.company_name', 'ООО Ромашка')
            ->assertJsonPath('pairs.0.organization_name', 'Пекадо')
            ->assertJsonPath('pairs.0.documents.0.id', $overdue->id)
            ->assertJsonPath('pairs.0.documents.0.overdue', true)
            ->assertJsonPath('scenarios.2.value', 'document')
            ->assertJsonCount(2, 'pairs.0.documents');
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

    private function service(): PaymentOrderService
    {
        return app(PaymentOrderService::class);
    }
}
