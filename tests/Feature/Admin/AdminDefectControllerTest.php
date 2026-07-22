<?php

namespace Tests\Feature\Admin;

use App\Models\ProductDefect;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDefectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Закупщик: заводим роль как на prod (вручную) + доназначаем defects.*,
     * что делает миграция grant_defect_permissions.
     */
    private function buyer(): User
    {
        $role = Role::firstOrCreate(['name' => 'buyer-manager', 'guard_name' => 'web']);

        foreach (['products.view', 'defects.view', 'defects.price', 'defects.publish'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Закупщик только с просмотром — без прав на цену и публикацию. */
    private function viewerOnly(): User
    {
        $role = Role::firstOrCreate(['name' => 'defects-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'defects.view', 'guard_name' => 'web']));
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function buyer_can_open_defects_list(): void
    {
        $this->actingAs($this->buyer())
            ->get('/admin/defects')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Pages/Defects/Index'));
    }

    #[Test]
    public function user_without_defect_permission_cannot_open_list(): void
    {
        $user = User::factory()->create();
        $user->assignRole('content-manager');

        $this->actingAs($user)->get('/admin/defects')->assertForbidden();
    }

    #[Test]
    public function buyer_sets_price_and_records_author(): void
    {
        $buyer = $this->buyer();
        $defect = ProductDefect::factory()->create();

        $this->actingAs($buyer)
            ->put("/admin/defects/{$defect->id}/price", ['price' => 499.90])
            ->assertRedirect();

        $defect->refresh();

        $this->assertSame('499.90', $defect->price);
        $this->assertSame($buyer->id, $defect->priced_by);
    }

    #[Test]
    public function price_must_be_positive(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/price", ['price' => 0])
            ->assertSessionHasErrors('price');

        $this->assertNull($defect->fresh()->price);
    }

    #[Test]
    public function defect_cannot_be_published_without_price(): void
    {
        $defect = ProductDefect::factory()->create(['price' => null]);

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true])
            ->assertSessionHas('error');

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    public function priced_defect_can_be_published_and_unpublished(): void
    {
        $defect = ProductDefect::factory()->priced(300)->create();

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true])
            ->assertRedirect();

        $this->assertTrue($defect->fresh()->is_published);

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => false])
            ->assertRedirect();

        $this->assertFalse($defect->fresh()->is_published);
    }

    #[Test]
    public function published_defect_becomes_sellable(): void
    {
        $defect = ProductDefect::factory()->priced(300)->create();

        $this->assertSame(0, ProductDefect::query()->sellable()->count());

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true]);

        $this->assertSame(1, ProductDefect::query()->sellable()->count());
    }

    #[Test]
    public function viewer_without_price_permission_is_forbidden_to_set_price(): void
    {
        $defect = ProductDefect::factory()->create();

        $this->actingAs($this->viewerOnly())
            ->put("/admin/defects/{$defect->id}/price", ['price' => 100])
            ->assertForbidden();
    }

    #[Test]
    public function viewer_without_publish_permission_is_forbidden_to_publish(): void
    {
        $defect = ProductDefect::factory()->priced(100)->create();

        $this->actingAs($this->viewerOnly())
            ->put("/admin/defects/{$defect->id}/publish", ['is_published' => true])
            ->assertForbidden();
    }

    #[Test]
    public function closed_defect_price_cannot_be_changed(): void
    {
        $defect = ProductDefect::factory()->priced(200)->closed()->create();

        $this->actingAs($this->buyer())
            ->put("/admin/defects/{$defect->id}/price", ['price' => 999])
            ->assertSessionHas('error');

        $this->assertSame('200.00', $defect->fresh()->price);
    }

    #[Test]
    public function index_filters_published_only(): void
    {
        ProductDefect::factory()->create();
        ProductDefect::factory()->sellable(100)->create(['defect_description' => 'Опубликованная']);

        $this->actingAs($this->buyer())
            ->get('/admin/defects?filter=published')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('defects.data', 1)
                ->where('defects.data.0.defect_description', 'Опубликованная')
            );
    }
}
