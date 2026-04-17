<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionCurrencyAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Currency $rub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->rub = Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'symbol' => '₽']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_region_store_with_currency(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.regions.store'), [
            'name' => 'Россия',
            'currency_id' => $this->rub->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('regions', [
            'name' => 'Россия',
            'currency_id' => $this->rub->id,
        ]);
    }

    public function test_region_store_without_currency(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.regions.store'), [
            'name' => 'Без валюты',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('regions', [
            'name' => 'Без валюты',
            'currency_id' => null,
        ]);
    }

    public function test_region_update_currency(): void
    {
        $region = Region::factory()->create(['currency_id' => null]);

        $byn = Currency::factory()->create(['code' => 'BYN', 'is_base' => false, 'symbol' => 'Br']);

        $response = $this->actingAs($this->admin)->put(route('admin.regions.update', $region), [
            'name' => $region->name,
            'currency_id' => $byn->id,
        ]);

        $response->assertRedirect();

        $region->refresh();
        $this->assertEquals($byn->id, $region->currency_id);
    }

    public function test_region_index_includes_currency(): void
    {
        Region::factory()->create(['currency_id' => $this->rub->id]);

        $response = $this->actingAs($this->admin)->get(route('admin.regions.index'));

        $response->assertOk();
    }
}
