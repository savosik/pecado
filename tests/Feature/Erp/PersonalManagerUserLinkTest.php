<?php

namespace Tests\Feature\Erp;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerCreated;
use App\Services\Erp\Handlers\HandlePartnerUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Регресс: привязка карточки менеджера к аккаунту (personal_managers.user_id) —
 * поле сайта, 1С о нём не знает. Обмен ходит по erp_uuid, поэтому события
 * от 1С не должны затирать привязку.
 *
 * Контракт с 1С не менялся, новых полей в payload нет — правило spec-first не
 * затрагивается, тест лишь фиксирует, что ERP-поток ничего не сломал.
 */
class PersonalManagerUserLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    #[Test]
    public function partner_updated_keeps_user_link_and_refreshes_name(): void
    {
        $account = User::factory()->create();
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'f2c1d4e7-0000-4000-a000-9ab000000000',
            'user_id' => $account->id,
            'name' => 'Старое Имя',
        ]);

        $client = User::factory()->create([
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-link-001',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'manager' => [
                'uuid' => 'f2c1d4e7-0000-4000-a000-9ab000000000',
                'name' => 'Иванов Иван Иванович',
            ],
        ]);

        $manager->refresh();

        $this->assertSame($account->id, $manager->user_id, 'Привязка аккаунта не должна теряться при обновлении из 1С.');
        $this->assertSame('Иванов Иван Иванович', $manager->name);
        $this->assertSame($manager->id, $client->refresh()->personal_manager_id);
    }

    #[Test]
    public function partner_created_makes_manager_without_account(): void
    {
        (new HandlePartnerCreated)->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'name' => 'ООО Ромашка',
            'email' => 'romashka@example.com',
            'manager' => [
                'uuid' => 'aaaaaaaa-0000-4000-a000-9ab000000000',
                'name' => 'Новый Менеджер',
            ],
        ]);

        $manager = PersonalManager::where('erp_uuid', 'aaaaaaaa-0000-4000-a000-9ab000000000')->first();

        $this->assertNotNull($manager);
        $this->assertNull($manager->user_id, 'Менеджер из 1С создаётся без аккаунта — привязка делается вручную.');
        $this->assertSame('Новый Менеджер', $manager->name);
    }

    #[Test]
    public function manager_reset_to_null_keeps_the_card_and_its_account(): void
    {
        $account = User::factory()->create();
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'f2c1d4e7-0000-4000-a000-9ab000000000',
            'user_id' => $account->id,
        ]);

        $client = User::factory()->create([
            'erp_id' => '550e8400-e29b-41d4-a716-446655440002',
            'personal_manager_id' => $manager->id,
        ]);

        // manager: null — 1С сняла закрепление у клиента, но карточка остаётся.
        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-link-002',
            'uuid' => '550e8400-e29b-41d4-a716-446655440002',
            'manager' => null,
        ]);

        $this->assertNull($client->refresh()->personal_manager_id);
        $this->assertSame($account->id, $manager->refresh()->user_id);
    }
}
