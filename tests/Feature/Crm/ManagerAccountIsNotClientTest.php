<?php

namespace Tests\Feature\Crm;

use App\Enums\UserKind;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePartnerCreated;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Менеджер отдела продаж не должен быть клиентом сам себе.
 *
 * В 1С сотрудники заведены партнёрами наравне с покупателями (компания работает
 * через несколько юрлиц), поэтому `partner.created` приходит и на менеджера,
 * а `manager` в payload проставляет ему personal_manager_id.
 */
class ManagerAccountIsNotClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function partner_matching_a_manager_email_is_created_as_staff(): void
    {
        PersonalManager::factory()->create([
            'name' => 'Курочкина Елена',
            'email' => 'manager@example.test',
        ]);

        (new HandlePartnerCreated)->handle([
            'event' => 'partner.created',
            'message_id' => 'msg-manager-as-partner',
            'uuid' => 'uuid-manager-as-partner',
            'login' => 'manager@example.test',
            'email' => 'manager@example.test',
            'name' => 'Курочкина Елена Валерьевна',
            'password' => 'from-1c-secret',
        ]);

        $created = User::where('email', 'manager@example.test')->firstOrFail();

        $this->assertSame(UserKind::STAFF, $created->user_kind);
    }

    #[Test]
    public function ordinary_partner_is_still_created_as_client(): void
    {
        PersonalManager::factory()->create(['email' => 'manager@example.test']);

        (new HandlePartnerCreated)->handle([
            'event' => 'partner.created',
            'message_id' => 'msg-ordinary-partner',
            'uuid' => 'uuid-ordinary-partner',
            'login' => 'buyer@example.test',
            'email' => 'buyer@example.test',
            'name' => 'ООО Покупатель',
            'password' => 'from-1c-secret',
        ]);

        $created = User::where('email', 'buyer@example.test')->firstOrFail();

        $this->assertSame(UserKind::CLIENT, $created->user_kind);
    }

    #[Test]
    public function linking_account_to_manager_card_marks_it_as_staff(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $account = User::factory()->create();
        $manager = PersonalManager::factory()->create(['user_id' => null]);

        $this->actingAs($admin)
            ->put(route('admin.personal-managers.update', $manager->id), [
                'name' => $manager->name,
                'user_id' => $account->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserKind::STAFF, $account->refresh()->user_kind);
    }

    #[Test]
    public function service_account_keeps_its_kind_when_linked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $account = User::factory()->service()->create();
        $manager = PersonalManager::factory()->create(['user_id' => null]);

        $this->actingAs($admin)
            ->put(route('admin.personal-managers.update', $manager->id), [
                'name' => $manager->name,
                'user_id' => $account->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserKind::SERVICE, $account->refresh()->user_kind);
    }
}
