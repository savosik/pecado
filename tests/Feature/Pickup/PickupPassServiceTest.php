<?php

namespace Tests\Feature\Pickup;

use App\Models\GoodsIssue;
use App\Models\Pickup\PickupHandover;
use App\Models\Pickup\PickupPass;
use App\Models\User;
use App\Services\Pickup\HandoverService;
use App\Services\Pickup\PickupPassException;
use App\Services\Pickup\PickupPassService;
use App\Services\Pickup\ScanResolver;
use App\Support\Erp\DocumentBarcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-09, pick-08, pick-11: пропуск курьеру, распознавание сканов, защита кода от перебора. */
class PickupPassServiceTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    private PickupPassService $passes;

    private User $client;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pickup.enabled' => true, 'pickup.wms_enabled' => true, 'pickup.handover_since' => null]);
        $this->passes = app(PickupPassService::class);
        $this->client = $this->pickupClient();
        $this->keeper = User::factory()->create(['name' => 'Иванов']);
    }

    #[Test]
    public function pass_for_everything_recalculates_contents_on_read(): void
    {
        $first = $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass, $token] = $this->passes->issueAll($this->client);

        $this->assertSame(64, strlen($pass->token_hash));
        $this->assertNotSame($token, $pass->token_hash);
        $this->assertSame($token, $this->passes->tokenOf($pass));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pass->code);
        $this->assertCount(1, $this->passes->contents($pass));

        // Дособрали ещё один заказ после выпуска — пропуск «на всё» его подхватывает.
        $second = $this->goodsIssueFor($this->pickupOrder($this->client));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->passes->contents($pass)->pluck('goods_issue_id')->all());
    }

    #[Test]
    public function nothing_ready_means_no_pass(): void
    {
        $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_PICK);

        $this->expectException(PickupPassException::class);
        $this->passes->issueAll($this->client);
    }

    #[Test]
    public function selected_pass_rejects_foreign_and_unready_goods_issues(): void
    {
        $mine = $this->goodsIssueFor($this->pickupOrder($this->client));
        $foreign = $this->goodsIssueFor($this->pickupOrder($this->pickupClient()));
        $unready = $this->goodsIssueFor($this->pickupOrder($this->client), GoodsIssue::STATUS_TO_CHECK);

        foreach ([[$mine->id, $foreign->id], [$unready->id], []] as $ids) {
            try {
                $this->passes->issueSelected($this->client, $ids);
                $this->fail('пропуск не должен выпускаться: '.json_encode($ids));
            } catch (PickupPassException) {
                $this->addToAssertionCount(1);
            }
        }

        [$pass] = $this->passes->issueSelected($this->client, [$mine->id]);
        $this->assertSame([$mine->id], $pass->items()->pluck('goods_issue_id')->all());
    }

    #[Test]
    public function two_couriers_get_two_passes_and_each_takes_only_own_sets(): void
    {
        $a = $this->goodsIssueFor($this->pickupOrder($this->client));
        $b = $this->goodsIssueFor($this->pickupOrder($this->client));
        [$passA] = $this->passes->issueSelected($this->client, [$a->id]);
        [$passB] = $this->passes->issueSelected($this->client, [$b->id]);

        app(HandoverService::class)->issue($a, $this->keeper, PickupHandover::METHOD_QR, $passA);

        $this->assertSame(PickupPass::STATUS_USED, $passA->fresh()->status);
        $this->assertSame(PickupPass::STATUS_ACTIVE, $passB->fresh()->status);
        $this->assertSame('ready', $this->passes->contents($passB->fresh())->first()['state']);
    }

    #[Test]
    public function one_set_belongs_to_one_pass_only(): void
    {
        $a = $this->goodsIssueFor($this->pickupOrder($this->client, ['erp_number' => '29УТ-040001']));
        $b = $this->goodsIssueFor($this->pickupOrder($this->client, ['erp_number' => '29УТ-040002']));
        [$passA] = $this->passes->issueSelected($this->client, [$a->id]);

        // Второй пропуск на тот же комплект — отказ с номером заказа.
        try {
            $this->passes->issueSelected($this->client, [$a->id, $b->id]);
            $this->fail('пересечение пропусков должно быть запрещено');
        } catch (PickupPassException $e) {
            $this->assertSame('already_in_pass', $e->reason);
            $this->assertStringContainsString('29УТ-040001', $e->getMessage());
        }

        // «На всё готовое» — только то, что не отдано другим: комплект A в него не входит.
        [$all] = $this->passes->issueAll($this->client);
        $this->assertSame([$b->id], $this->passes->contents($all)->pluck('goods_issue_id')->all());
        $this->assertSame([$a->id], $this->passes->contents($passA)->pluck('goods_issue_id')->all());

        // Второй пропуск «на всё» — отказ; после отзыва первого — можно.
        try {
            $this->passes->issueAll($this->client);
            $this->fail();
        } catch (PickupPassException $e) {
            $this->assertSame('all_pass_exists', $e->reason);
        }
        $this->passes->revoke($all);
        $this->passes->issueAll($this->client);
        $this->addToAssertionCount(1);

        // Карта покрытия для кабинета: A — у выбранного пропуска, B — нет.
        $covered = $this->passes->coveredBySelected($this->client);
        $this->assertSame($passA->id, $covered->get($a->id)?->id);
        $this->assertNull($covered->get($b->id));
    }

    #[Test]
    public function all_pass_is_refused_when_everything_ready_is_already_assigned(): void
    {
        $a = $this->goodsIssueFor($this->pickupOrder($this->client));
        $this->passes->issueSelected($this->client, [$a->id]);

        $this->expectException(PickupPassException::class);
        $this->passes->issueAll($this->client);
    }

    #[Test]
    public function selected_pass_shows_why_a_set_cannot_be_issued(): void
    {
        $rolled = $this->goodsIssueFor($this->pickupOrder($this->client));
        $cancelled = $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass] = $this->passes->issueSelected($this->client, [$rolled->id, $cancelled->id]);

        $this->moveIssue($rolled, GoodsIssue::STATUS_TO_PICK);
        $cancelled->delete();

        $states = $this->passes->contents($pass)->pluck('state', 'goods_issue_id');
        $this->assertSame('picking', $states[$rolled->id]);
        $this->assertSame('cancelled', $states[$cancelled->id]);
        $this->assertFalse($this->passes->contents($pass)->contains('can_issue', true));
    }

    #[Test]
    public function revoked_and_expired_passes_stop_working(): void
    {
        $this->goodsIssueFor($this->pickupOrder($this->client));
        [$revoked, $token] = $this->passes->issueAll($this->client);
        $this->passes->revoke($revoked);

        $this->assertFalse($this->passes->findByToken($token)->isUsable());
        $this->assertNull($this->passes->findUsableByCode($revoked->code));

        [$expired] = $this->passes->issueAll($this->client);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull($this->passes->findUsableByCode($expired->code));
        $this->assertSame(PickupPass::STATUS_EXPIRED, $expired->fresh()->effective_status);

        $this->expectException(PickupPassException::class);
        $this->passes->revoke($revoked->fresh());
    }

    #[Test]
    public function cancelled_handover_reopens_used_pass(): void
    {
        $issue = $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass] = $this->passes->issueSelected($this->client, [$issue->id]);
        $handover = app(HandoverService::class)->issue($issue, $this->keeper, PickupHandover::METHOD_QR, $pass);
        $this->assertSame(PickupPass::STATUS_USED, $pass->fresh()->status);

        app(HandoverService::class)->cancel($handover, $this->keeper, 'отметили не тот комплект');

        $this->assertSame(PickupPass::STATUS_ACTIVE, $pass->fresh()->status);
    }

    #[Test]
    public function scan_resolver_recognises_link_code_and_1c_document_barcode(): void
    {
        $order = $this->pickupOrder($this->client, ['erp_number' => '29УТ-014170']);
        $issue = $this->goodsIssueFor($order);
        [$pass, $token] = $this->passes->issueAll($this->client);
        $scans = app(ScanResolver::class);

        $byLink = $scans->resolve('https://pecado.ru/p/'.$token, $this->keeper);
        $this->assertSame(['pass', $pass->id, 'qr'], [$byLink['kind'], $byLink['pass']->id, $byLink['via']]);

        $byCode = $scans->resolve(substr($pass->code, 0, 3).' '.substr($pass->code, 3), $this->keeper);
        $this->assertSame(['pass', 'code'], [$byCode['kind'], $byCode['via']]);

        // Типовой штрихкод 1С: GUID расходного ордера как десятичное число.
        $byIssue = $scans->resolve(DocumentBarcode::fromGuid($issue->uuid), $this->keeper);
        $this->assertSame([$issue->id], $byIssue['goods_issues']->pluck('id')->all());

        // Лист мог быть напечатан из заказа клиента — находим ордер через заказ.
        $byOrder = $scans->resolve(DocumentBarcode::fromGuid($order->uuid), $this->keeper);
        $this->assertSame([$issue->id], $byOrder['goods_issues']->pluck('id')->all());

        $byNumber = $scans->resolve('29ут014170', $this->keeper);
        $this->assertSame([$issue->id], $byNumber['goods_issues']->pluck('id')->all());
    }

    #[Test]
    public function unknown_scans_are_logged_and_code_guessing_is_throttled(): void
    {
        config(['pickup.code_attempts' => 3]);
        $scans = app(ScanResolver::class);

        $this->assertSame('miss', $scans->resolve('4607001234567', $this->keeper)['kind']);
        $this->assertDatabaseHas('pickup_scan_misses', ['raw' => '4607001234567', 'kind' => 'scan', 'user_id' => $this->keeper->id]);

        foreach (['000001', '000002', '000003'] as $guess) {
            $this->assertSame('miss', $scans->resolve($guess, $this->keeper)['kind']);
        }

        $this->goodsIssueFor($this->pickupOrder($this->client));
        [$pass] = $this->passes->issueAll($this->client);
        $this->assertSame('throttled', $scans->resolve($pass->code, $this->keeper)['kind'], 'после трёх промахов даже верный код не принимается');
        $this->assertSame('pass', $scans->resolve($pass->code, User::factory()->create())['kind'], 'ограничение — на сотрудника, не на всех');
    }
}
