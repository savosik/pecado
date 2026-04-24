<?php

namespace Tests\Feature\Erp;

use App\Jobs\PublishContractorToErpJob;
use App\Models\Company;
use App\Models\ContractorBalance;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use App\Services\Erp\Handlers\HandleContractorUpdated;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * US-07 v13.2: интеграционный тест полного UUID-workflow контрагентов.
 *
 * Воспроизводит исходный бизнес-кейс:
 *  1. Пользователь создаёт Company на сайте с ошибочным ИНН.
 *  2. Сайт публикует contractor.created → 1С.
 *  3. Менеджер в 1С исправляет ИНН и возвращает contractor.updated с UUID.
 *  4. Сайт привязывает Company.erp_id и обновляет tax_id.
 *  5. Последующие shipment.created и balance.updated приходят с UUID и
 *     корректно матчатся по Company.erp_id, а не по старому ИНН.
 */
class ContractorUuidWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function full_workflow_from_company_creation_to_balance_sync(): void
    {
        Queue::fake();

        // ── 1. Партнёр уже выгружен в 1С (имеет erp_id) ──
        $user = User::factory()->create([
            'erp_id' => '00000000-0000-4000-a000-000000000100',
        ]);

        // ── 2. Пользователь создаёт Company на сайте с tax_id, а затем (опционально) менеджер правит ИНН в 1С.
        //       Сценарий: сначала сайт публикует исходный tax_id, 1С создаёт контрагента с этим ИНН
        //       и возвращает UUID → сайт привязывает erp_id. Затем менеджер правит ИНН в 1С → 1С шлёт
        //       ещё один contractor.updated с тем же UUID и новым tax_id → сайт обновляет tax_id по UUID.
        $initialTaxId = '12321321312312';
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => $initialTaxId,
            'name' => 'ООО Ромашка',
            'country' => 'RU',
            'erp_id' => null,
        ]);

        Queue::assertPushed(PublishContractorToErpJob::class, function ($job) use ($user, $initialTaxId) {
            return $job->payload['event'] === 'contractor.created'
                && $job->payload['partner_uuid'] === $user->erp_id
                && $job->payload['tax_id'] === $initialTaxId;
        });

        // ── 3. 1С возвращает contractor.updated с присвоенным UUID и ИСХОДНЫМ tax_id (привязка erp_id) ──
        $assignedUuid = '00000000-0000-4000-a000-000000001000';
        $handlerUpdated = new HandleContractorUpdated;
        $handlerUpdated->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-flow-upd-001',
            'uuid' => $assignedUuid,
            'partner_uuid' => $user->erp_id,
            'tax_id' => $initialTaxId,
            'name' => 'ООО Ромашка',
        ]);

        $company->refresh();
        $this->assertEquals($assignedUuid, $company->erp_id, 'Company.erp_id должен быть привязан (backfill по tax_id + user_id)');

        // ── 4. Менеджер в 1С правит ИНН и присылает второй contractor.updated по UUID ──
        $correctedTaxId = '1232132131231'; // одна цифра удалена
        $handlerUpdated->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-flow-upd-002',
            'uuid' => $assignedUuid,
            'partner_uuid' => $user->erp_id,
            'tax_id' => $correctedTaxId,
        ]);

        $company->refresh();
        $this->assertEquals($correctedTaxId, $company->tax_id, 'tax_id должен быть обновлён по UUID-матчингу');
        $this->assertEquals($assignedUuid, $company->erp_id, 'erp_id остаётся тем же');

        // ── 5. 1С отправляет shipment.created с UUID и исправленным ИНН ──
        $handlerShipment = new HandleShipmentCreated;
        $handlerShipment->handle([
            'event' => 'shipment.created',
            'message_id' => 'msg-flow-ship-001',
            'uuid' => '00000000-0000-4000-a000-000000002000',
            'contractor_uuid' => $assignedUuid,
            'tax_id' => $correctedTaxId,
            'partner_uuid' => $user->erp_id,
            'number' => 'REAL-001',
            'date' => '2026-04-24',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [],
        ]);

        $shipment = Shipment::where('uuid', '00000000-0000-4000-a000-000000002000')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals($company->id, $shipment->company_id, 'Shipment должна привязаться к нашей Company по UUID');
        $this->assertEquals($user->id, $shipment->user_id);

        // ── 6. 1С отправляет balance.updated с UUID и исправленным ИНН ──
        $handlerBalance = new HandleBalanceUpdated;
        $handlerBalance->handle([
            'event' => 'balance.updated',
            'message_id' => 'msg-flow-bal-001',
            'partner_uuid' => $user->erp_id,
            'updated_at' => '2026-04-24T12:00:00Z',
            'contractors' => [
                [
                    'uuid' => $assignedUuid,
                    'tax_id' => $correctedTaxId,
                    'current_balance' => -5000,
                    'overdue_debt' => 0,
                    'overdue_details' => [],
                ],
            ],
        ]);

        $balance = ContractorBalance::where('user_id', $user->id)
            ->where('tax_id', $correctedTaxId)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals($company->id, $balance->company_id);
        $this->assertEquals($assignedUuid, $balance->contractor_uuid, 'contractor_uuid должен заполниться');
        $this->assertEquals(-5000, (float) $balance->current_balance);
    }

    #[Test]
    public function catchup_publishes_company_when_partner_gets_erp_id(): void
    {
        Queue::fake();

        // Партнёр без erp_id
        $user = User::factory()->create([
            'erp_id' => null,
            'email' => 'catchup@example.com',
            'name' => 'Catchup User',
        ]);

        // Company создан с tax_id, но partner без erp_id — публикация отложена
        Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '9999999999',
            'country' => 'RU',
            'erp_id' => null,
        ]);

        Queue::assertNotPushed(PublishContractorToErpJob::class);

        // 1С присылает partner.updated и привязывает erp_id
        $user->update(['erp_id' => '00000000-0000-4000-a000-000000000200']);

        // Вызываем catchup вручную (в реальном workflow его вызовет HandlePartnerUpdated)
        \App\Listeners\PublishContractorToErp::catchupForUser($user->refresh());

        Queue::assertPushed(PublishContractorToErpJob::class, function ($job) {
            return $job->payload['tax_id'] === '9999999999'
                && $job->payload['partner_uuid'] === '00000000-0000-4000-a000-000000000200';
        });
    }
}
