<?php

namespace Tests\Feature\Api\Client;

use App\Models\GoodsIssue;
use App\Models\Pickup\PickupPass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Pickup\PickupTestHelpers;

/** pick-14: самовывоз в клиентском API v1 — стадия заказа, готовое к выдаче, пропуска. */
class ClientApiPickupTest extends ClientApiTestCase
{
    use PickupTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.handover_since' => null]);
    }

    #[Test]
    public function orders_carry_fulfilment_stage(): void
    {
        $order = $this->pickupOrder($this->client, ['company_id' => $this->company->id]);
        $this->goodsIssueFor($order);

        $this->api('GET', '/orders')->assertOk()->assertJsonPath('data.0.fulfilment.stage', 'ready');
        $this->api('GET', "/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.fulfilment.label', 'Собран, ждёт выдачи')
            ->assertJsonPath('data.fulfilment.is_pickup', true);
    }

    #[Test]
    public function ready_list_and_pass_lifecycle(): void
    {
        $ready = $this->goodsIssueFor($this->pickupOrder($this->client, ['company_id' => $this->company->id, 'erp_number' => '29УТ-017001']));
        $this->goodsIssueFor($this->pickupOrder($this->client, ['company_id' => $this->company->id]), GoodsIssue::STATUS_TO_PICK);

        $this->api('GET', '/pickup/ready')->assertOk()
            ->assertJsonCount(1, 'data.ready')->assertJsonCount(1, 'data.picking')
            ->assertJsonPath('data.ready.0.goods_issue_id', $ready->id)
            ->assertJsonPath('data.ready.0.orders.0.number', '29УТ-017001')
            ->assertJsonPath('meta.schedule.week_text', 'пн–сб, 9:00–21:00');

        $created = $this->api('POST', '/pickup/passes', ['scope' => 'selected', 'goods_issue_ids' => [$ready->id], 'courier_name' => 'Олег'], ['Idempotency-Key' => 'pass-1'])
            ->assertSuccessful()->assertJsonPath('data.to_issue', 1)->assertJsonPath('data.courier_name', 'Олег');
        $this->assertStringContainsString('/p/', $created->json('data.url'));

        // Повтор с тем же ключом не плодит пропуска.
        $this->api('POST', '/pickup/passes', ['scope' => 'selected', 'goods_issue_ids' => [$ready->id], 'courier_name' => 'Олег'], ['Idempotency-Key' => 'pass-1'])->assertSuccessful();
        $this->assertSame(1, PickupPass::count());
        $this->assertSame('api', PickupPass::first()->source);

        $this->api('GET', '/pickup/passes')->assertOk()->assertJsonPath('meta.total', 1);

        $passId = $created->json('data.pass_id');
        $this->api('POST', "/pickup/passes/{$passId}/revoke", [], ['Idempotency-Key' => 'revoke-1'])->assertSuccessful()->assertJsonPath('data.revoked', true);
        $this->api('GET', '/pickup/passes')->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function foreign_goods_issue_and_empty_shelf_are_rejected_with_codes(): void
    {
        $foreign = $this->goodsIssueFor($this->pickupOrder($this->pickupClient()));

        $this->api('POST', '/pickup/passes', ['scope' => 'selected', 'goods_issue_ids' => [$foreign->id]], ['Idempotency-Key' => 'k1'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'not_available');
        $this->api('POST', '/pickup/passes', ['scope' => 'all'], ['Idempotency-Key' => 'k2'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'nothing_ready');
    }

    #[Test]
    public function closed_gate_hides_section_and_fulfilment(): void
    {
        config(['pickup.enabled' => false]);
        $order = $this->pickupOrder($this->client, ['company_id' => $this->company->id]);
        $this->goodsIssueFor($order);

        $this->api('GET', '/pickup/ready')->assertStatus(403)->assertJsonPath('errors.0.code', 'pickup_unavailable');
        $this->api('GET', "/orders/{$order->id}")->assertOk()->assertJsonPath('data.fulfilment', null);
    }
}
