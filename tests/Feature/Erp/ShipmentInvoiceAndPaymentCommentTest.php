<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use App\Services\Erp\Handlers\HandleShipmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Счёт-фактура реализации и комментарий платежа из 1С (протокол v15.16.0).
 *
 * Оба реквизита 1С присылала и раньше — места в контракте под них не было.
 * По счёту-фактуре все двенадцать вхождений слова `invoice` в спецификации
 * относились к значению `invoice_date` перечисления `basis` графика оплаты,
 * то есть к совпадению имён. Комментарий в 1С заполнен во всех 2 841 платёжных
 * документах за 2026 год.
 */
class ShipmentInvoiceAndPaymentCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
    }

    #[Test]
    public function shipment_stores_invoice_number_and_date(): void
    {
        app(HandleShipmentCreated::class)->handle([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-0000000050f1',
            'number' => '29УТ-004417',
            'contractor_uuid' => '00000000-0000-4000-a000-0000000050c1',
            'invoice' => ['number' => 'СФ-000123', 'date' => '2026-08-08'],
        ]);

        $shipment = Shipment::where('uuid', '00000000-0000-4000-a000-0000000050f1')->first();

        $this->assertNotNull($shipment);
        $this->assertSame('СФ-000123', $shipment->invoice_number);
        $this->assertSame('2026-08-08', $shipment->invoice_date?->format('Y-m-d'));
    }

    /**
     * 1С может присылать реквизит не по всем документам сразу — отсутствие ключа
     * не должно стирать то, что уже сохранено.
     */
    #[Test]
    public function missing_invoice_key_does_not_clear_saved_values(): void
    {
        Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000050f2',
            'invoice_number' => 'СФ-000999',
            'invoice_date' => '2026-07-01',
        ]);

        app(HandleShipmentUpdated::class)->handle([
            'event' => 'shipment.updated',
            'uuid' => '00000000-0000-4000-a000-0000000050f2',
            'status' => 'completed',
        ]);

        $shipment = Shipment::where('uuid', '00000000-0000-4000-a000-0000000050f2')->first();

        $this->assertSame('СФ-000999', $shipment->invoice_number);
        $this->assertSame('2026-07-01', $shipment->invoice_date?->format('Y-m-d'));
    }

    #[Test]
    public function explicit_null_invoice_clears_the_values(): void
    {
        Shipment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000050f3',
            'invoice_number' => 'СФ-000777',
            'invoice_date' => '2026-07-01',
        ]);

        app(HandleShipmentUpdated::class)->handle([
            'event' => 'shipment.updated',
            'uuid' => '00000000-0000-4000-a000-0000000050f3',
            'invoice' => null,
        ]);

        $shipment = Shipment::where('uuid', '00000000-0000-4000-a000-0000000050f3')->first();

        $this->assertNull($shipment->invoice_number);
        $this->assertNull($shipment->invoice_date);
    }

    /**
     * Ключевое требование: комментарий 1С не должен затирать заметку сотрудника.
     * `payments.comment` — единственное поле платежа, которое ведёт сайт, и в 1С
     * оно не уходит.
     */
    #[Test]
    public function erp_comment_does_not_overwrite_the_managers_note(): void
    {
        $company = Company::factory()->create([
            'erp_id' => '00000000-0000-4000-a000-0000000051c1',
            'tax_id' => '7755000001',
        ]);

        Payment::factory()->create([
            'uuid' => '00000000-0000-4000-a000-0000000051f1',
            'comment' => 'Заметка менеджера: клиент просил разнести на две накладные',
        ]);

        app(HandlePaymentCreated::class)->handle([
            'event' => 'payment.created',
            'uuid' => '00000000-0000-4000-a000-0000000051f1',
            'number' => '29УТ-002488',
            'date' => '2026-08-08T10:15:00+03:00',
            'amount' => 1000,
            'contractor_uuid' => $company->erp_id,
            'comment' => 'Комментарий из 1С',
            'purpose' => 'Оплата по счёту 123 от 01.08.2026',
        ]);

        $payment = Payment::where('uuid', '00000000-0000-4000-a000-0000000051f1')->first();

        $this->assertSame('Комментарий из 1С', $payment->erp_comment);
        $this->assertSame(
            'Заметка менеджера: клиент просил разнести на две накладные',
            $payment->comment,
            'Комментарий сотрудника обязан пережить доставку документа из 1С',
        );
        $this->assertSame('Оплата по счёту 123 от 01.08.2026', $payment->purpose, 'purpose — отдельное поле');
    }

    /**
     * v15.16.1: у счёта-фактуры в 1С два номера — внутренний («29УТ-0006968»)
     * и печатный («УТ-6968»). Клиент сверяет по бумаге, поэтому храним оба,
     * а показываем печатный.
     */
    #[Test]
    public function invoice_keeps_both_internal_and_printed_numbers(): void
    {
        app(HandleShipmentCreated::class)->handle([
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-0000000054f1',
            'number' => '29УТ-004417',
            'contractor_uuid' => '00000000-0000-4000-a000-0000000054c1',
            'invoice' => [
                'number' => '29УТ-0006968',
                'number_display' => 'УТ-6968',
                'date' => '2026-08-08',
            ],
        ]);

        $shipment = Shipment::where('uuid', '00000000-0000-4000-a000-0000000054f1')->first();

        $this->assertSame('29УТ-0006968', $shipment->invoice_number);
        $this->assertSame('УТ-6968', $shipment->invoice_number_display);
    }

    /**
     * `checking` — седьмое значение статуса расходного ордера (v15.16.1).
     * В 1С это самостоятельное состояние документа, а не «К проверке»
     * с признаком работы кладовщика.
     */
    #[Test]
    public function goods_issue_accepts_checking_status(): void
    {
        $validator = app(ErpMessageValidator::class);

        $result = $validator->validate('goods_issue.updated', [
            'event' => 'goods_issue.updated',
            'uuid' => '00000000-0000-4000-a000-0000000055f1',
            'number' => 'УТ-00009419',
            'status' => 'checking',
            'items' => [
                ['product_uuid' => '00000000-0000-4000-a000-0000000055a1', 'quantity' => 1],
            ],
        ]);

        $this->assertTrue($result['valid'], implode('; ', $result['errors']));

        $this->assertContains('checking', \App\Models\GoodsIssue::STATUSES);
        $this->assertSame('В процессе проверки', \App\Models\GoodsIssue::STATUS_LABELS['checking']);
        $this->assertContains('checking', \App\Models\GoodsIssue::ACTIVE_STATUSES, 'Ордер в проверке ещё не уехал');
    }

    /**
     * `event` ограничен константой: сообщение с чужим значением не должно
     * проходить валидацию не своей схемы.
     */
    #[Test]
    public function event_constant_rejects_foreign_event_name(): void
    {
        $validator = app(ErpMessageValidator::class);

        $valid = $validator->validate('shipment.created', [
            'event' => 'shipment.created',
            'uuid' => '00000000-0000-4000-a000-0000000052f1',
            'number' => '29УТ-000001',
            'contractor_uuid' => '00000000-0000-4000-a000-0000000052c1',
            'tax_id' => '7766000001',
        ]);

        $this->assertTrue($valid['valid'], implode('; ', $valid['errors']));

        $foreign = $validator->validate('shipment.created', [
            'event' => 'order.created',
            'uuid' => '00000000-0000-4000-a000-0000000052f1',
            'number' => '29УТ-000001',
            'contractor_uuid' => '00000000-0000-4000-a000-0000000052c1',
            'tax_id' => '7766000001',
        ]);

        $this->assertFalse($foreign['valid'], 'Чужой event не должен проходить валидацию схемы реализации');
    }

    /**
     * Категории — единственный случай, где одна схема обслуживает два события,
     * поэтому там перечисление, а не константа.
     */
    #[Test]
    public function category_schema_accepts_both_of_its_events(): void
    {
        $validator = app(ErpMessageValidator::class);

        foreach (['category.created', 'category.updated'] as $event) {
            $result = $validator->validate($event, [
                'event' => $event,
                'uuid' => '00000000-0000-4000-a000-0000000053f1',
                'name' => 'Категория',
            ]);

            $this->assertTrue($result['valid'], $event.': '.implode('; ', $result['errors']));
        }
    }
}
