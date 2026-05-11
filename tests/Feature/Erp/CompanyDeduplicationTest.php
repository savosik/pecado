<?php

namespace Tests\Feature\Erp;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Erp\Handlers\HandleContractorCreated;
use App\Services\Erp\Handlers\HandleOrderCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v13.3: регрессия по инциденту 2026-04-25 (51 дубль «21 ООО»).
 *
 * Проверяет дедупликацию контрагентов через ERP-шину:
 * - матчинг по ИНН+КПП без user_id (ИНН/КПП юридически уникальны);
 * - повторные order.created от 1С не плодят дубли Company;
 * - soft-deleted восстанавливается, а не дублируется;
 * - backfill erp_id при матчинге по ИНН.
 */
class CompanyDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_created_finds_existing_company_by_tax_id_without_user(): void
    {
        // Существующая Company без юзера и без erp_id (например, созданная ручным сидом)
        Company::withoutEvents(function () {
            Company::withoutGlobalScopes()->create([
                'user_id' => null,
                'erp_id' => null,
                'country' => 'RU',
                'name' => '21 ООО',
                'legal_name' => 'ООО "21"',
                'tax_id' => '5410165679',
                'tax_code' => '541001001',
            ]);
        });

        $handler = app(HandleOrderCreated::class);

        // Шлём 5 последовательных order.created от того же контрагента (разные uuid заказов)
        for ($i = 1; $i <= 5; $i++) {
            $handler->handle([
                'event' => 'order.created',
                'message_id' => "msg-dedup-{$i}",
                'uuid' => "order-dedup-{$i}",
                'status' => 'pending_approval',
                'contractor' => [
                    'country' => 'RU',
                    'name' => '21 ООО',
                    'legal_name' => 'ООО "21"',
                    'tax_id' => '5410165679',
                    'tax_code' => '541001001',
                ],
                'items' => [],
            ]);
        }

        $this->assertEquals(
            1,
            Company::withoutGlobalScopes()->where('tax_id', '5410165679')->count(),
            'Должна остаться ровно одна Company — все order.created должны находить её по ИНН+КПП'
        );

        $this->assertEquals(5, Order::count(), 'Должно быть создано 5 заказов');

        $companyId = Company::withoutGlobalScopes()->where('tax_id', '5410165679')->value('id');
        $this->assertEquals(
            5,
            Order::where('company_id', $companyId)->count(),
            'Все 5 заказов должны быть привязаны к одной Company'
        );
    }

    #[Test]
    public function order_created_regression_51_duplicates_with_no_existing_company(): void
    {
        // Регрессия: 1С шлёт 51 order.created без contractor.uuid и без существующей Company.
        // Старый код создавал 51 Company. Новый код создаёт 1 при первом, остальные находят её.
        $handler = app(HandleOrderCreated::class);

        for ($i = 1; $i <= 10; $i++) {
            $handler->handle([
                'event' => 'order.created',
                'message_id' => "msg-regression-{$i}",
                'uuid' => "order-regression-{$i}",
                'status' => 'pending_approval',
                'contractor' => [
                    'country' => 'RU',
                    'name' => '21 ООО',
                    'tax_id' => '5410165679',
                    'tax_code' => '541001001',
                ],
                'items' => [],
            ]);
        }

        $this->assertEquals(
            1,
            Company::withoutGlobalScopes()->where('tax_id', '5410165679')->count(),
            'Регрессия инцидента 2026-04-25: повторные order.created без UUID не должны плодить дубли'
        );
    }

    #[Test]
    public function contractor_created_backfills_erp_id_on_existing_company(): void
    {
        // Сценарий: контрагент был создан на сайте без erp_id, потом 1С прислала contractor.created
        // с UUID — должно произойти обновление, а не создание дубля.
        Company::withoutEvents(function () {
            Company::withoutGlobalScopes()->create([
                'user_id' => null,
                'erp_id' => null,
                'country' => 'RU',
                'name' => 'Старое название',
                'tax_id' => '7701234567',
                'tax_code' => '770101001',
            ]);
        });

        $user = User::factory()->create([
            'erp_id' => 'partner-uuid-backfill',
            'status' => UserStatus::ACTIVE,
        ]);

        $handler = new HandleContractorCreated;
        $handler->handle([
            'event' => 'contractor.created',
            'uuid' => 'contractor-uuid-backfill',
            'partner_uuid' => 'partner-uuid-backfill',
            'name' => 'Новое название от 1С',
            'tax_id' => '7701234567',
            'tax_code' => '770101001',
        ]);

        $this->assertEquals(
            1,
            Company::withoutGlobalScopes()->where('tax_id', '7701234567')->count(),
            'Дубль создаваться не должен'
        );

        $this->assertDatabaseHas('companies', [
            'tax_id' => '7701234567',
            'tax_code' => '770101001',
            'erp_id' => 'contractor-uuid-backfill',
            'user_id' => $user->id,
            'name' => 'Новое название от 1С',
        ]);
    }

    #[Test]
    public function order_created_restores_soft_deleted_company(): void
    {
        // Soft-deleted Company с тем же ИНН/КПП должна восстанавливаться,
        // а не превращаться в источник дубля.
        $company = Company::withoutEvents(function () {
            return Company::withoutGlobalScopes()->create([
                'user_id' => null,
                'country' => 'RU',
                'name' => 'Удалённая',
                'tax_id' => '5555555555',
                'tax_code' => '555501001',
            ]);
        });

        $company->delete();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);

        $handler = app(HandleOrderCreated::class);
        $handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-restore-001',
            'uuid' => 'order-restore-001',
            'status' => 'pending_approval',
            'contractor' => [
                'country' => 'RU',
                'name' => 'Восстановлена',
                'tax_id' => '5555555555',
                'tax_code' => '555501001',
            ],
            'items' => [],
        ]);

        $this->assertEquals(
            1,
            Company::withoutGlobalScopes()->where('tax_id', '5555555555')->count(),
            'Дубль не должен создаваться'
        );

        $restored = Company::withoutGlobalScopes()->where('tax_id', '5555555555')->first();
        $this->assertEquals($company->id, $restored->id, 'Это та же Company, просто восстановлена');
        $this->assertNull($restored->deleted_at, 'deleted_at сброшен');
    }

    #[Test]
    public function ip_without_kpp_is_treated_as_distinct_per_tax_id(): void
    {
        // Два разных ИП (12-значный ИНН, КПП = '') — две Company, не конфликтуют.
        // Повторный payload для одного ИП не создаёт дубль.
        $handler = app(HandleOrderCreated::class);

        foreach ([
            ['order' => 'order-ip-001', 'tax_id' => '500100732259', 'name' => 'ИП Иванов'],
            ['order' => 'order-ip-002', 'tax_id' => '770301287604', 'name' => 'ИП Петров'],
            ['order' => 'order-ip-003', 'tax_id' => '500100732259', 'name' => 'ИП Иванов (повтор)'],
        ] as $idx => $data) {
            $handler->handle([
                'event' => 'order.created',
                'message_id' => "msg-ip-{$idx}",
                'uuid' => $data['order'],
                'status' => 'pending_approval',
                'contractor' => [
                    'country' => 'RU',
                    'name' => $data['name'],
                    'tax_id' => $data['tax_id'],
                    // tax_code намеренно опущен — ИП не имеет КПП
                ],
                'items' => [],
            ]);
        }

        $this->assertEquals(2, Company::withoutGlobalScopes()->count(), 'Два разных ИП = две Company');
        $this->assertEquals(1, Company::withoutGlobalScopes()->where('tax_id', '500100732259')->count(), 'Повтор по тому же ИП не плодит дубль');
        $this->assertEquals(1, Company::withoutGlobalScopes()->where('tax_id', '770301287604')->count());
    }

    #[Test]
    public function different_tax_codes_for_same_tax_id_are_treated_as_distinct(): void
    {
        // Обособленные подразделения у одного юрлица: один ИНН — несколько КПП.
        // Это должно создавать разные Company.
        $handler = app(HandleOrderCreated::class);

        foreach ([
            ['order' => 'order-branch-msk', 'kpp' => '770101001'],
            ['order' => 'order-branch-spb', 'kpp' => '780101001'],
        ] as $idx => $data) {
            $handler->handle([
                'event' => 'order.created',
                'message_id' => "msg-branch-{$idx}",
                'uuid' => $data['order'],
                'status' => 'pending_approval',
                'contractor' => [
                    'country' => 'RU',
                    'name' => 'Газпром (филиал)',
                    'tax_id' => '7736050003',
                    'tax_code' => $data['kpp'],
                ],
                'items' => [],
            ]);
        }

        $this->assertEquals(
            2,
            Company::withoutGlobalScopes()->where('tax_id', '7736050003')->count(),
            'Две Company с разным КПП должны существовать раздельно'
        );
    }
}
