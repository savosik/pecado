<?php

namespace Tests\Feature\Admin;

use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonalManagerUserSelectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    #[Test]
    public function admin_can_link_account_to_manager_card(): void
    {
        $manager = PersonalManager::factory()->create(['user_id' => null]);
        $account = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.personal-managers.update', $manager->id), [
                'name' => $manager->name,
                'user_id' => $account->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($account->id, $manager->refresh()->user_id);
        $this->assertSame($manager->id, $account->refresh()->managerProfile?->id);
    }

    #[Test]
    public function linking_an_already_linked_account_fails_validation(): void
    {
        $account = User::factory()->create();
        PersonalManager::factory()->create(['user_id' => $account->id]);
        $other = PersonalManager::factory()->create(['user_id' => null]);

        // Русская ошибка формы, а не 500 от unique-индекса.
        $this->actingAs($this->admin)
            ->put(route('admin.personal-managers.update', $other->id), [
                'name' => $other->name,
                'user_id' => $account->id,
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertNull($other->refresh()->user_id);
    }

    #[Test]
    public function manager_can_keep_its_own_account_on_update(): void
    {
        $account = User::factory()->create();
        $manager = PersonalManager::factory()->create(['user_id' => $account->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.personal-managers.update', $manager->id), [
                'name' => 'Новое Имя',
                'user_id' => $account->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($account->id, $manager->refresh()->user_id);
        $this->assertSame('Новое Имя', $manager->name);
    }

    #[Test]
    public function account_can_be_unlinked(): void
    {
        $account = User::factory()->create();
        $manager = PersonalManager::factory()->create(['user_id' => $account->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.personal-managers.update', $manager->id), [
                'name' => $manager->name,
                'user_id' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($manager->refresh()->user_id);
    }

    #[Test]
    public function edit_page_offers_free_accounts_and_the_current_one(): void
    {
        $linkedAccount = User::factory()->create();
        $manager = PersonalManager::factory()->create(['user_id' => $linkedAccount->id]);

        $takenAccount = User::factory()->create();
        PersonalManager::factory()->create(['user_id' => $takenAccount->id]);

        $freeAccount = User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.personal-managers.edit', $manager->id))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($linkedAccount, $freeAccount, $takenAccount) {
                $ids = collect($page->toArray()['props']['users'])->pluck('id');

                $this->assertTrue($ids->contains($linkedAccount->id), 'Текущий привязанный аккаунт должен остаться в списке.');
                $this->assertTrue($ids->contains($freeAccount->id), 'Свободный аккаунт должен предлагаться.');
                $this->assertFalse($ids->contains($takenAccount->id), 'Занятый другой карточкой аккаунт предлагаться не должен.');
            });
    }
}
