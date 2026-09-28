<?php

namespace Tests\Feature\Erp;

use App\Models\ErpPendingPrice;
use App\Models\Product;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.12.3, топик №12 Agent Hub: price.updated обогнал product.created.
 *
 * 02.09.2026 цена 955067-L (2020) пришла в одну секунду с карточкой, была
 * обработана первой и выброшена — карточка жила с нулевой базой три недели.
 * Тест гоняет оба события через ErpIncomingJob (валидация схемой + handler)
 * в обоих порядках.
 */
class PriceBeforeProductTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '0bca6392-a535-11f1-8947-9cf448d36917';

    private function fire(array $payload, string $queue): void
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

    private function priceUpdated(float $price, string $messageId): void
    {
        $this->fire([
            'event' => 'price.updated',
            'message_id' => $messageId,
            'product_uuid' => self::UUID,
            'price' => $price,
        ], 'erp_in.prices');
    }

    private function productCreated(string $messageId = 'msg-product-created'): void
    {
        $this->fire([
            'event' => 'product.created',
            'message_id' => $messageId,
            'uuid' => self::UUID,
            'name' => 'Боди ALEXA из материала Wetlook, черное, L',
            'sku' => '955067-L',
            'code' => 'УТ-00008267',
        ], 'erp_in.catalog');
    }

    private function product(): Product
    {
        return Product::withoutGlobalScopes()->where('external_id', self::UUID)->firstOrFail();
    }

    #[Test]
    public function price_arriving_before_product_is_applied_on_creation(): void
    {
        $this->priceUpdated(2020, 'msg-price-1');

        $this->assertDatabaseHas('erp_pending_prices', ['product_uuid' => self::UUID, 'message_id' => 'msg-price-1']);

        $this->productCreated();

        $this->assertSame(2020.0, (float) $this->product()->base_price);
        $this->assertSame(0, ErpPendingPrice::count());
    }

    #[Test]
    public function latest_parked_price_wins(): void
    {
        $this->priceUpdated(1900, 'msg-price-1');
        $this->priceUpdated(2020, 'msg-price-2');

        $this->productCreated();

        $this->assertSame(2020.0, (float) $this->product()->base_price);
    }

    #[Test]
    public function price_arriving_after_product_is_applied_directly(): void
    {
        $this->productCreated();
        $this->assertSame(0.0, (float) $this->product()->base_price);

        $this->priceUpdated(2020, 'msg-price-1');

        $this->assertSame(2020.0, (float) $this->product()->base_price);
        $this->assertSame(0, ErpPendingPrice::count());
    }

    #[Test]
    public function repeated_product_created_keeps_base_price(): void
    {
        $this->productCreated('msg-product-1');
        $this->priceUpdated(2020, 'msg-price-1');

        // Повторная выгрузка карточки (1С шлёт её при каждом изменении) цену не трогает.
        $this->productCreated('msg-product-2');

        $this->assertSame(2020.0, (float) $this->product()->base_price);
    }

    #[Test]
    public function stale_parked_price_does_not_override_fresh_direct_one(): void
    {
        // Гонка: цена отложена, карточка создана, но до apply() пришла свежая цена.
        ErpPendingPrice::create(['product_uuid' => self::UUID, 'price' => 1500, 'message_id' => 'old']);
        Product::factory()->create(['external_id' => self::UUID, 'base_price' => 0]);

        $this->priceUpdated(2020, 'msg-price-fresh');
        $this->productCreated();

        $this->assertSame(2020.0, (float) $this->product()->base_price);
        $this->assertSame(0, ErpPendingPrice::count());
    }
}
