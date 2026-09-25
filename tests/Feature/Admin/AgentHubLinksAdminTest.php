<?php

namespace Tests\Feature\Admin;

use App\Models\AgentHubLink;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Админка ссылок на пульт Agent Hub: выдача поимённо и отзыв одной кнопкой.
 */
class AgentHubLinksAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    #[Test]
    public function admin_sees_links_with_both_addresses(): void
    {
        $link = AgentHubLink::factory()->create(['label' => 'Админ 1С']);

        $this->actingAs($this->admin())
            ->get('/admin/agent-topics/links')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/AgentTopics/Links')
                ->has('links', 1)
                ->where('links.0.url', url("/agent-hub/{$link->token}"))
                ->where('links.0.api_url', url("/api/agent-hub/links/{$link->token}/topics")));
    }

    #[Test]
    public function admin_issues_link(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/agent-topics/links', ['label' => 'Наш админ', 'note' => 'для дежурного'])
            ->assertRedirect();

        $link = AgentHubLink::firstOrFail();

        $this->assertSame('Наш админ', $link->label);
        $this->assertSame($admin->id, $link->created_by);
        $this->assertSame(64, strlen($link->token));
    }

    #[Test]
    public function admin_revokes_link_and_page_stops_opening(): void
    {
        $link = AgentHubLink::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/agent-topics/links/{$link->id}")
            ->assertRedirect();

        $this->assertNotNull($link->fresh()->revoked_at);
        $this->get("/agent-hub/{$link->token}")->assertNotFound();
    }

    #[Test]
    public function user_without_permission_cannot_see_links(): void
    {
        $user = User::factory()->create();
        $user->assignRole('content-manager');

        $this->actingAs($user)->get('/admin/agent-topics/links')->assertForbidden();
    }
}
