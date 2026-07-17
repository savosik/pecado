<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-analytics.view', 'crm-dashboard.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function analyst(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('crm-analytics.view');

        return $user->fresh();
    }

    private function samplePayload(): array
    {
        return [
            'filters' => [
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'brand_ids' => [7],
                'product_ids' => [42],
            ],
            'products' => [['id' => 42, 'name' => 'Вакуумно-волновой', 'sku' => '9015108']],
            'compareMode' => 'year',
            'compareOffset' => 2,
        ];
    }

    #[Test]
    public function analyst_can_save_and_list_own_presets(): void
    {
        $user = $this->analyst();

        $res = $this->actingAs($user)->postJson('/crm/analytics/presets', [
            'name' => 'Июнь по бренду',
            'payload' => $this->samplePayload(),
        ]);

        $res->assertCreated();
        $res->assertJsonPath('name', 'Июнь по бренду');
        $res->assertJsonPath('payload.compareMode', 'year');

        $this->assertDatabaseHas('crm_analytics_filter_presets', [
            'user_id' => $user->id,
            'name' => 'Июнь по бренду',
        ]);

        // Пресет отдаётся на странице отчёта.
        $this->actingAs($user)->get('/crm/analytics')->assertOk();
        $this->assertSame(1, $user->crmAnalyticsFilterPresets()->count());
    }

    #[Test]
    public function preset_name_is_required(): void
    {
        $user = $this->analyst();

        $this->actingAs($user)
            ->postJson('/crm/analytics/presets', ['payload' => $this->samplePayload()])
            ->assertUnprocessable();
    }

    #[Test]
    public function owner_can_delete_preset(): void
    {
        $user = $this->analyst();
        $preset = $user->crmAnalyticsFilterPresets()->create([
            'name' => 'Тест',
            'payload' => $this->samplePayload(),
        ]);

        $this->actingAs($user)
            ->deleteJson("/crm/analytics/presets/{$preset->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('crm_analytics_filter_presets', ['id' => $preset->id]);
    }

    #[Test]
    public function foreign_preset_deletion_returns_404_not_403(): void
    {
        $owner = $this->analyst();
        $other = $this->analyst();
        $preset = $owner->crmAnalyticsFilterPresets()->create([
            'name' => 'Чужой',
            'payload' => $this->samplePayload(),
        ]);

        $this->actingAs($other)
            ->deleteJson("/crm/analytics/presets/{$preset->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('crm_analytics_filter_presets', ['id' => $preset->id]);
    }

    #[Test]
    public function saving_requires_analytics_permission(): void
    {
        // Есть доступ в CRM (crm-dashboard), но нет права на отчёты → 403 от гейта.
        $user = User::factory()->create();
        $user->givePermissionTo('crm-dashboard.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)
            ->postJson('/crm/analytics/presets', [
                'name' => 'Нельзя',
                'payload' => $this->samplePayload(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('crm_analytics_filter_presets', 0);
    }
}
