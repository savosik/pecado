<?php

namespace Tests\Feature\Erp;

use App\Models\ErpPendingPrice;
use App\Models\ErpScheduledPrice;
use App\Models\Product;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.13.0, топик №15 Agent Hub: дата вступления цены в силу.
 *
 * 27.09.2026 установку цен датой 28.09 00:00 сайт показал сразу — 9015108
 * стоил 2 295 вместо 3 845 за сутки до срока. Тест гоняет сообщения через
 * ErpIncomingJob (валидация схемой + handler) и планировщик включения —
 * по сценариям тест-плана 1.6б.
 */
class ScheduledPriceTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = 'b7e1c2d3-5a60-11f0-8b3e-00155d010203';

    private const DOC_A = '0c2f4e10-9a1b-11f1-8948-b00eaec447ca';

    private const DOC_B = '1d3a5f20-9a1b-11f1-8948-b00eaec447ca';

    protected function setUp(): void
    {
        parent::setUp();

        // 27.09 18:00 МСК — вечер перед переоценкой, как в инциденте.
        Carbon::setTestNow(Carbon::parse('2026-09-27T18:00:00+03:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fire(array $payload, string $queue = 'erp_in.prices'): void
    {
        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        (new ErpIncomingJob(
            app(),
            $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class),
            $amqpMessage,
            'rabbitmq-erp-incoming',
            $queue,
        ))->fire();
    }

    private function priceUpdated(float $price, ?string $effectiveFrom = null, ?string $documentUuid = self::DOC_A): void
    {
        $payload = [
            'event' => 'price.updated',
            'message_id' => 'msg-price-'.uniqid(),
            'product_uuid' => self::PRODUCT,
            'price' => $price,
        ];

        if ($effectiveFrom !== null) {
            $payload['effective_from'] = $effectiveFrom;
            $payload['document_uuid'] = $documentUuid;
            $payload['document_number'] = 'УТ-00000123';
        }

        $this->fire($payload);
    }

    private function priceCancelled(string $documentUuid): void
    {
        $this->fire([
            'event' => 'price.cancelled',
            'message_id' => 'msg-cancel-'.uniqid(),
            'document_uuid' => $documentUuid,
            'product_uuids' => [self::PRODUCT],
        ]);
    }

    private function moveClockTo(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment));
        $this->artisan('erp:activate-scheduled-prices')->assertSuccessful();
    }

    private function price(): float
    {
        return (float) Product::withoutGlobalScopes()->where('external_id', self::PRODUCT)->firstOrFail()->base_price;
    }

    private function product(float $basePrice = 3845): void
    {
        Product::factory()->create(['external_id' => self::PRODUCT, 'base_price' => $basePrice]);
    }

    #[Test]
    public function future_price_waits_for_its_date_and_switches_on_by_itself(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');

        $this->assertSame(3845.0, $this->price(), 'до даты действует прежняя цена');
        $this->assertDatabaseHas('erp_scheduled_prices', ['product_uuid' => self::PRODUCT, 'document_uuid' => self::DOC_A]);

        $this->moveClockTo('2026-09-27T23:59:00+03:00');
        $this->assertSame(3845.0, $this->price(), 'за минуту до срока цена ещё прежняя');

        $this->moveClockTo('2026-09-28T00:00:30+03:00');
        $this->assertSame(2295.0, $this->price(), 'в срок цена включилась без новых сообщений');
        $this->assertSame(0, ErpScheduledPrice::count());
    }

    #[Test]
    public function effective_from_offset_is_respected(): void
    {
        $this->product();

        // Тот же момент в UTC: 27.09 21:00Z = 28.09 00:00 МСК.
        $this->priceUpdated(2295, '2026-09-27T21:00:00Z');

        $this->moveClockTo('2026-09-27T23:59:30+03:00');
        $this->assertSame(3845.0, $this->price());

        $this->moveClockTo('2026-09-28T00:00:10+03:00');
        $this->assertSame(2295.0, $this->price());
    }

    #[Test]
    public function reposting_document_with_another_date_moves_the_schedule(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');
        $this->priceUpdated(2195, '2026-10-01T00:00:00+03:00');

        $this->assertSame(1, ErpScheduledPrice::count(), 'по паре товар × документ запись одна');

        $this->moveClockTo('2026-09-28T00:01:00+03:00');
        $this->assertSame(3845.0, $this->price(), 'в прежнюю дату ничего не происходит');

        $this->moveClockTo('2026-10-01T00:01:00+03:00');
        $this->assertSame(2195.0, $this->price());
    }

    #[Test]
    public function reposting_document_with_past_date_applies_now_and_drops_schedule(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');
        $this->priceUpdated(2295, '2026-09-27T12:00:00+03:00');

        $this->assertSame(2295.0, $this->price());
        $this->assertSame(0, ErpScheduledPrice::count());
    }

    #[Test]
    public function cancelled_document_does_not_switch_on(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');
        $this->priceCancelled(self::DOC_A);

        $this->assertSame(0, ErpScheduledPrice::count());
        $this->assertSame(3845.0, $this->price(), 'отмена действующую цену не трогает');

        $this->moveClockTo('2026-09-28T00:01:00+03:00');
        $this->assertSame(3845.0, $this->price());
    }

    #[Test]
    public function cancellation_of_another_document_keeps_schedule(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');
        $this->priceCancelled(self::DOC_B);

        $this->assertSame(1, ErpScheduledPrice::count());
    }

    #[Test]
    public function rollback_after_activation_applies_older_document_price(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');
        $this->moveClockTo('2026-09-28T00:01:00+03:00');
        $this->assertSame(2295.0, $this->price());

        // Документ распровели после вступления: 1С шлёт отмену и действующую
        // цену прежнего документа с его (более ранней) датой.
        $this->priceCancelled(self::DOC_A);
        $this->priceUpdated(3845, '2026-08-17T13:43:48+03:00', self::DOC_B);

        $this->assertSame(3845.0, $this->price());
    }

    #[Test]
    public function legacy_message_without_date_applies_immediately(): void
    {
        $this->product();

        $this->priceUpdated(2295);

        $this->assertSame(2295.0, $this->price());
        $this->assertSame(0, ErpScheduledPrice::count());
    }

    #[Test]
    public function several_documents_switch_on_in_date_order(): void
    {
        $this->product();

        $this->priceUpdated(1990, '2026-10-01T00:00:00+03:00', self::DOC_B);
        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00', self::DOC_A);

        $this->moveClockTo('2026-09-28T00:01:00+03:00');
        $this->assertSame(2295.0, $this->price());

        $this->moveClockTo('2026-10-01T00:01:00+03:00');
        $this->assertSame(1990.0, $this->price());
    }

    #[Test]
    public function missed_run_applies_the_latest_due_price(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00', self::DOC_A);
        $this->priceUpdated(1990, '2026-10-01T00:00:00+03:00', self::DOC_B);

        // Планировщик стоял, обе даты прошли — действует более поздняя, как в регистре 1С.
        $this->moveClockTo('2026-10-02T09:00:00+03:00');

        $this->assertSame(1990.0, $this->price());
        $this->assertSame(0, ErpScheduledPrice::count());
    }

    #[Test]
    public function fresh_message_wins_over_due_but_not_yet_activated_price(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00', self::DOC_A);

        // Дата наступила, планировщик ещё не отработал — а 1С уже прислала новую цену.
        Carbon::setTestNow(Carbon::parse('2026-09-28T00:00:20+03:00'));
        $this->priceUpdated(2100, '2026-09-28T00:00:15+03:00', self::DOC_B);
        $this->moveClockTo('2026-09-28T00:01:00+03:00');

        $this->assertSame(2100.0, $this->price(), 'планировщик не возвращает поглощённую цену');
    }

    #[Test]
    public function future_price_for_product_not_yet_created_is_not_lost(): void
    {
        $this->priceUpdated(2295, '2026-09-28T00:00:00+03:00');

        $this->moveClockTo('2026-09-28T00:01:00+03:00');
        $this->assertSame(0, ErpScheduledPrice::count());
        $this->assertDatabaseHas('erp_pending_prices', ['product_uuid' => self::PRODUCT]);

        $this->fire([
            'event' => 'product.created',
            'message_id' => 'msg-product-created',
            'uuid' => self::PRODUCT,
            'name' => 'Satisfyer Penguin, черный',
            'sku' => '9015108',
            'code' => 'УТ-00009015',
        ], 'erp_in.catalog');

        $this->assertSame(2295.0, $this->price());
        $this->assertSame(0, ErpPendingPrice::count());
    }

    #[Test]
    public function date_without_offset_is_rejected_by_schema(): void
    {
        $this->product();

        $this->priceUpdated(2295, '2026-09-28T00:00:00');

        $this->assertSame(3845.0, $this->price());
        $this->assertSame(0, ErpScheduledPrice::count());
    }

    #[Test]
    public function date_without_document_is_rejected_by_schema(): void
    {
        $this->product();

        $this->fire([
            'event' => 'price.updated',
            'message_id' => 'msg-no-doc',
            'product_uuid' => self::PRODUCT,
            'price' => 2295,
            'effective_from' => '2026-09-28T00:00:00+03:00',
        ]);

        $this->assertSame(3845.0, $this->price());
        $this->assertSame(0, ErpScheduledPrice::count());
    }
}
