<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\GoodsIssue;
use App\Services\Erp\Handlers\HandleGoodsIssueCreated;
use App\Services\Erp\Handlers\HandleGoodsIssueDeleted;
use App\Services\Erp\Handlers\HandleGoodsIssueUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleGoodsIssueUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $event, array $overrides = []): array
    {
        return array_merge([
            'event' => $event,
            'message_id' => 'msg-gi-'.uniqid(),
            'uuid' => self::UUID,
            'number' => 'УТ-00009419',
            'date' => '2026-07-08T13:25:55+03:00',
            'status' => GoodsIssue::STATUS_TO_PICK,
            'items' => [
                ['product_uuid' => 'p1', 'quantity' => 15],
            ],
        ], $overrides);
    }

    private function seedOrder(string $status = GoodsIssue::STATUS_TO_PICK): GoodsIssue
    {
        app(HandleGoodsIssueCreated::class)->handle($this->payload('goods_issue.created', [
            'status' => $status,
        ]));

        return GoodsIssue::firstWhere('uuid', self::UUID);
    }

    #[Test]
    public function it_updates_status_and_records_the_transition(): void
    {
        $this->seedOrder();

        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated', [
            'status' => GoodsIssue::STATUS_TO_SHIP,
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', self::UUID);
        $history = $goodsIssue->statusHistories;

        $this->assertSame(GoodsIssue::STATUS_TO_SHIP, $goodsIssue->status);
        $this->assertCount(2, $history);
        $this->assertSame(GoodsIssue::STATUS_TO_PICK, $history->last()->from_status);
        $this->assertSame(GoodsIssue::STATUS_TO_SHIP, $history->last()->to_status);
    }

    #[Test]
    public function repeated_update_with_same_status_does_not_pollute_history(): void
    {
        // 1С досылает документ целиком при любом изменении. Без проверки на фактическую
        // смену журнал забился бы повторами одного и того же статуса.
        $this->seedOrder();

        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated'));
        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated'));

        $this->assertCount(1, GoodsIssue::firstWhere('uuid', self::UUID)->statusHistories);
    }

    #[Test]
    public function it_replaces_items_instead_of_appending(): void
    {
        $this->seedOrder();

        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated', [
            'items' => [
                ['product_uuid' => 'p2', 'quantity' => 7],
                ['product_uuid' => 'p3', 'quantity' => 3],
            ],
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', self::UUID);

        $this->assertSame(2, $goodsIssue->items_count);
        $this->assertEquals(10, (float) $goodsIssue->total_quantity);
        $this->assertSame(['p2', 'p3'], $goodsIssue->items->pluck('product_uuid')->all());
    }

    #[Test]
    public function missing_packages_key_keeps_stored_packages(): void
    {
        app(HandleGoodsIssueCreated::class)->handle($this->payload('goods_issue.created', [
            'packages' => [['number' => 1, 'positions_count' => 2]],
        ]));

        // Ключа packages в payload нет — сохранённые упаковки не трогаем.
        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated', [
            'status' => GoodsIssue::STATUS_CHECKED,
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', self::UUID);

        $this->assertSame(1, $goodsIssue->packages_count);
        $this->assertCount(1, $goodsIssue->packages);
    }

    #[Test]
    public function empty_packages_array_clears_stored_packages(): void
    {
        app(HandleGoodsIssueCreated::class)->handle($this->payload('goods_issue.created', [
            'packages' => [['number' => 1, 'positions_count' => 2]],
        ]));

        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated', [
            'packages' => [],
        ]));

        $goodsIssue = GoodsIssue::firstWhere('uuid', self::UUID);

        $this->assertSame(0, $goodsIssue->packages_count);
        $this->assertCount(0, $goodsIssue->packages);
    }

    #[Test]
    public function update_for_unknown_order_creates_it(): void
    {
        // Потерянное или пришедшее не по порядку `created` иначе навсегда оставило бы
        // склад без ордера — восстановить его можно только повторной выгрузкой из 1С.
        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated'));

        $this->assertNotNull(GoodsIssue::firstWhere('uuid', self::UUID));
    }

    #[Test]
    public function it_restores_soft_deleted_order(): void
    {
        $this->seedOrder();

        app(HandleGoodsIssueDeleted::class)->handle([
            'event' => 'goods_issue.deleted',
            'uuid' => self::UUID,
        ]);

        $this->assertNull(GoodsIssue::firstWhere('uuid', self::UUID));

        app(HandleGoodsIssueUpdated::class)->handle($this->payload('goods_issue.updated', [
            'status' => GoodsIssue::STATUS_SHIPPED,
        ]));

        $restored = GoodsIssue::firstWhere('uuid', self::UUID);

        $this->assertNotNull($restored);
        $this->assertSame(GoodsIssue::STATUS_SHIPPED, $restored->status);
    }
}
