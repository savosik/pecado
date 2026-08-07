<?php

namespace Tests\Feature\Erp;

use App\Jobs\PublishContractorToErpJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Handlers\HandleContractorUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v15.14.0: перепривязка контрагента к другому партнёру через contractor.updated.
 *
 * Исходный кейс (прод, 06.08.2026): в 1С контрагента перевели на нового партнёра
 * и прислали contractor.updated с новым partner_uuid. Сообщение обработалось со
 * статусом success, но companies.user_id остался у старого партнёра — клиент
 * видел в ЛК 0 организаций, и кнопка «Оформить заказ» была неактивна.
 */
class ContractorRebindPartnerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changed_partner_uuid_moves_company_to_new_owner(): void
    {
        Queue::fake();

        $oldOwner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000201']);
        $newOwner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000202']);

        $company = Company::factory()->create([
            'user_id' => $oldOwner->id,
            'erp_id' => '00000000-0000-4000-a000-000000002001',
            'tax_id' => '772480243400',
            'name' => 'Войдаков Дмитрий Евгеньевич ИП',
            'country' => 'RU',
        ]);

        (new HandleContractorUpdated)->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-rebind-001',
            'uuid' => $company->erp_id,
            'partner_uuid' => $newOwner->erp_id,
            'country' => 'RU',
        ]);

        $company->refresh();

        $this->assertEquals(
            $newOwner->id,
            $company->user_id,
            'Company должна переехать к партнёру из нового partner_uuid'
        );

        // Бизнес-эффект: организация появляется в ЛК нового клиента и уходит у старого —
        // именно это разблокирует кнопку «Оформить заказ» на /checkout.
        $this->assertEquals(1, $newOwner->companies()->count());
        $this->assertEquals(0, $oldOwner->companies()->count());
    }

    #[Test]
    public function rebind_does_not_publish_contractor_updated_back_to_erp(): void
    {
        Queue::fake();

        $oldOwner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000203']);
        $newOwner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000204']);

        $company = Company::factory()->create([
            'user_id' => $oldOwner->id,
            'erp_id' => '00000000-0000-4000-a000-000000002002',
            'tax_id' => '7724802434',
            'country' => 'RU',
        ]);

        // Создание Company публикует contractor.created — очищаем фейк,
        // чтобы проверить именно отсутствие исходящих от самой перепривязки.
        Queue::fake();

        (new HandleContractorUpdated)->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-rebind-002',
            'uuid' => $company->erp_id,
            'partner_uuid' => $newOwner->erp_id,
        ]);

        $this->assertEquals($newOwner->id, $company->fresh()->user_id);
        Queue::assertNotPushed(PublishContractorToErpJob::class);
    }

    #[Test]
    public function unknown_partner_uuid_keeps_current_owner(): void
    {
        Queue::fake();

        $owner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000205']);

        $company = Company::factory()->create([
            'user_id' => $owner->id,
            'erp_id' => '00000000-0000-4000-a000-000000002003',
            'tax_id' => '7724802435',
            'name' => 'ООО Ромашка',
            'country' => 'RU',
        ]);

        (new HandleContractorUpdated)->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-rebind-003',
            'uuid' => $company->erp_id,
            'partner_uuid' => '00000000-0000-4000-a000-0000000009ff', // такого партнёра нет
            'name' => 'ООО Ромашка Плюс',
        ]);

        $company->refresh();

        $this->assertEquals($owner->id, $company->user_id, 'Неизвестный партнёр не должен менять владельца');
        $this->assertNotNull($company->user_id, 'Владелец не должен обнуляться');
        $this->assertEquals('ООО Ромашка Плюс', $company->name, 'Реквизиты при этом обновляются');
    }

    #[Test]
    public function missing_partner_uuid_keeps_current_owner(): void
    {
        Queue::fake();

        $owner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000206']);

        $company = Company::factory()->create([
            'user_id' => $owner->id,
            'erp_id' => '00000000-0000-4000-a000-000000002004',
            'tax_id' => '7724802436',
            'country' => 'RU',
        ]);

        (new HandleContractorUpdated)->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-rebind-004',
            'uuid' => $company->erp_id,
            'phone' => '+79163732571',
        ]);

        $company->refresh();

        $this->assertEquals($owner->id, $company->user_id);
        $this->assertEquals('+79163732571', $company->phone);
    }

    #[Test]
    public function orphan_company_gets_bound_to_resolved_partner(): void
    {
        Queue::fake();

        $owner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000207']);

        // Контрагент приехал раньше партнёра — HandleContractorCreated оставил user_id = NULL.
        $company = Company::factory()->create([
            'user_id' => null,
            'erp_id' => '00000000-0000-4000-a000-000000002005',
            'tax_id' => '7724802437',
            'country' => 'RU',
        ]);

        (new HandleContractorUpdated)->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-rebind-005',
            'uuid' => $company->erp_id,
            'partner_uuid' => $owner->erp_id,
        ]);

        $this->assertEquals($owner->id, $company->fresh()->user_id);
    }

    #[Test]
    public function same_partner_uuid_is_idempotent(): void
    {
        Queue::fake();

        $owner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000208']);

        $company = Company::factory()->create([
            'user_id' => $owner->id,
            'erp_id' => '00000000-0000-4000-a000-000000002006',
            'tax_id' => '7724802438',
            'country' => 'RU',
        ]);

        foreach (['msg-rebind-006', 'msg-rebind-007'] as $messageId) {
            (new HandleContractorUpdated)->handle([
                'event' => 'contractor.updated',
                'message_id' => $messageId,
                'uuid' => $company->erp_id,
                'partner_uuid' => $owner->erp_id,
            ]);
        }

        $this->assertEquals($owner->id, $company->fresh()->user_id);
    }

    #[Test]
    public function history_stays_with_previous_owner(): void
    {
        Queue::fake();

        $oldOwner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-000000000209']);
        $newOwner = User::factory()->create(['erp_id' => '00000000-0000-4000-a000-00000000020a']);

        $company = Company::factory()->create([
            'user_id' => $oldOwner->id,
            'erp_id' => '00000000-0000-4000-a000-000000002007',
            'tax_id' => '7724802439',
            'country' => 'RU',
        ]);

        $order = Order::factory()->create([
            'user_id' => $oldOwner->id,
            'company_id' => $company->id,
        ]);

        (new HandleContractorUpdated)->handle([
            'event' => 'contractor.updated',
            'message_id' => 'msg-rebind-008',
            'uuid' => $company->erp_id,
            'partner_uuid' => $newOwner->erp_id,
        ]);

        $this->assertEquals($newOwner->id, $company->fresh()->user_id);
        $this->assertEquals(
            $oldOwner->id,
            $order->fresh()->user_id,
            'Ранее созданные заказы сайт не переносит — история остаётся за прежним пользователем'
        );
    }
}
