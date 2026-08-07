<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\GoodsIssue;
use App\Models\GoodsIssueItem;
use App\Models\GoodsIssueStatusHistory;
use App\Services\Erp\Handlers\HandleGoodsIssueDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleGoodsIssueDeletedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_soft_deletes_the_order(): void
    {
        $goodsIssue = GoodsIssue::factory()->create([
            'uuid' => 'gi-uuid',
            'status' => GoodsIssue::STATUS_TO_PICK,
        ]);

        app(HandleGoodsIssueDeleted::class)->handle([
            'event' => 'goods_issue.deleted',
            'uuid' => 'gi-uuid',
        ]);

        $this->assertSoftDeleted('goods_issues', ['id' => $goodsIssue->id]);
    }

    #[Test]
    public function it_records_cancellation_without_changing_order_status(): void
    {
        // Собственный статус не трогаем: после восстановления документа должно быть
        // видно, на каком этапе его застала отмена.
        $goodsIssue = GoodsIssue::factory()->create([
            'uuid' => 'gi-uuid',
            'status' => GoodsIssue::STATUS_CHECKED,
        ]);

        app(HandleGoodsIssueDeleted::class)->handle([
            'event' => 'goods_issue.deleted',
            'uuid' => 'gi-uuid',
        ]);

        $entry = GoodsIssueStatusHistory::where('goods_issue_id', $goodsIssue->id)->latest('id')->first();

        $this->assertSame(GoodsIssue::STATUS_CHECKED, $entry->from_status);
        $this->assertSame(GoodsIssueStatusHistory::STATUS_CANCELLED, $entry->to_status);
        $this->assertSame('Отменён в 1С', $entry->to_status_label);
        $this->assertSame(
            GoodsIssue::STATUS_CHECKED,
            GoodsIssue::withTrashed()->find($goodsIssue->id)->status,
        );
    }

    #[Test]
    public function it_keeps_items_and_history(): void
    {
        $goodsIssue = GoodsIssue::factory()->create(['uuid' => 'gi-uuid']);
        GoodsIssueItem::factory()->create(['goods_issue_id' => $goodsIssue->id]);

        app(HandleGoodsIssueDeleted::class)->handle([
            'event' => 'goods_issue.deleted',
            'uuid' => 'gi-uuid',
        ]);

        $this->assertDatabaseCount('goods_issue_items', 1);
        $this->assertDatabaseCount('goods_issue_status_histories', 1);
    }

    #[Test]
    public function unknown_uuid_is_not_an_error(): void
    {
        // 1С могла отменить документ, который сайту никогда не выгружался.
        app(HandleGoodsIssueDeleted::class)->handle([
            'event' => 'goods_issue.deleted',
            'uuid' => 'never-seen',
        ]);

        $this->assertSame(0, GoodsIssue::withTrashed()->count());
    }

    #[Test]
    public function it_ignores_payload_without_uuid(): void
    {
        $goodsIssue = GoodsIssue::factory()->create(['uuid' => 'gi-uuid']);

        app(HandleGoodsIssueDeleted::class)->handle(['event' => 'goods_issue.deleted']);

        $this->assertDatabaseHas('goods_issues', ['id' => $goodsIssue->id, 'deleted_at' => null]);
    }
}
