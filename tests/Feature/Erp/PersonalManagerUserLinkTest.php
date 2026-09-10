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
 * Регресс: справочник менеджеров сайта (`personal_managers`) — поле сайта.
 *
 * С v16.10.0 поле `manager` в событиях партнёра 1С сайт игнорирует: закрепление
 * ведёт РОП в CRM, а карточки менеджеров, их привязка к аккаунтам (user_id)
 * и имена из шины не меняются и не создаются. Тест фиксирует, что ERP-поток
 * ничего из этого не трогает, даже если 1С продолжит слать поле.
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
    public function partner_updated_leaves_manager_card_account_and_assignment_alone(): void
    {
        $account = User::factory()->create();
        $manager = PersonalManager::factory()->create([
            'erp_uuid' => 'f2c1d4e7-0000-4000-a000-9ab000000000',
            'user_id' => $account->id,
            'name' => 'Имя на сайте',
        ]);
        $ours = PersonalManager::factory()->create(['name' => 'Закреплён РОПом']);

        $client = User::factory()->create([
            'erp_id' => '550e8400-e29b-41d4-a716-446655440000',
            'personal_manager_id' => $ours->id,
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

        $this->assertSame($account->id, $manager->user_id);
        $this->assertSame('Имя на сайте', $manager->name, 'Имя карточки из 1С больше не приезжает.');
        $this->assertSame($ours->id, $client->refresh()->personal_manager_id, 'Закрепление РОПа 1С не перезаписывает.');
    }

    #[Test]
    public function partner_created_does_not_create_manager_card(): void
    {
        (new HandlePartnerCreated)->handle([
            'event' => 'partner.created',
            'uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'name' => 'ООО Ромашка',
            'email' => 'romashka@example.com',
            'password' => 'pass12345',
            'manager' => [
                'uuid' => 'aaaaaaaa-0000-4000-a000-9ab000000000',
                'name' => 'Новый Менеджер',
            ],
        ]);

        $this->assertNull(PersonalManager::where('erp_uuid', 'aaaaaaaa-0000-4000-a000-9ab000000000')->first());
        $this->assertNull(User::where('email', 'romashka@example.com')->firstOrFail()->personal_manager_id);
    }

    #[Test]
    public function manager_null_from_erp_does_not_unassign_client(): void
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

        (new HandlePartnerUpdated)->handle([
            'event' => 'partner.updated',
            'message_id' => 'msg-link-002',
            'uuid' => '550e8400-e29b-41d4-a716-446655440002',
            'manager' => null,
        ]);

        $this->assertSame($manager->id, $client->refresh()->personal_manager_id);
        $this->assertSame($account->id, $manager->refresh()->user_id);
    }
}
