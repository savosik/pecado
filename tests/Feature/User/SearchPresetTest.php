<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Models\UserSearchPreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function enablePresets(): void
    {
        config(['search-cabinet.presets' => true]);
    }

    // ---------- Off-flag поведение ----------

    #[Test]
    public function index_returns_404_when_flag_disabled(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/search-presets/orders');
        $response->assertNotFound();
    }

    #[Test]
    public function store_returns_404_when_flag_disabled(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/cabinet/search-presets', [
                'section' => 'orders',
                'name' => 'Поиск №1',
                'filters' => ['status' => ['confirmed']],
            ]);
        $response->assertNotFound();
        $this->assertSame(0, UserSearchPreset::count());
    }

    #[Test]
    public function destroy_returns_404_when_flag_disabled(): void
    {
        $preset = UserSearchPreset::create([
            'user_id' => $this->user->id,
            'section' => 'orders',
            'name' => 'Some preset',
            'filters' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/cabinet/search-presets/'.$preset->id);
        $response->assertNotFound();
        $this->assertNotNull(UserSearchPreset::find($preset->id));
    }

    // ---------- On-flag поведение ----------

    #[Test]
    public function store_creates_preset_for_current_user(): void
    {
        $this->enablePresets();

        $response = $this->actingAs($this->user)
            ->postJson('/cabinet/search-presets', [
                'section' => 'orders',
                'name' => 'Активные за месяц',
                'filters' => ['status' => ['confirmed'], 'date_from' => '2026-04-01'],
            ]);

        $response->assertCreated();
        $preset = UserSearchPreset::sole();
        $this->assertSame($this->user->id, $preset->user_id);
        $this->assertSame('orders', $preset->section);
        $this->assertSame('Активные за месяц', $preset->name);
        $this->assertSame(['status' => ['confirmed'], 'date_from' => '2026-04-01'], $preset->filters);
    }

    #[Test]
    public function store_validates_section_and_name(): void
    {
        $this->enablePresets();

        $this->actingAs($this->user)
            ->postJson('/cabinet/search-presets', [
                'section' => 'unknown',
                'name' => 'X',
                'filters' => [],
            ])
            ->assertUnprocessable();

        $this->actingAs($this->user)
            ->postJson('/cabinet/search-presets', [
                'section' => 'orders',
                'name' => '',
                'filters' => [],
            ])
            ->assertUnprocessable();
    }

    #[Test]
    public function index_returns_only_current_user_presets_for_section(): void
    {
        $this->enablePresets();

        $other = User::factory()->create();
        UserSearchPreset::create([
            'user_id' => $this->user->id,
            'section' => 'orders',
            'name' => 'Мой поиск 1',
            'filters' => [],
        ]);
        UserSearchPreset::create([
            'user_id' => $this->user->id,
            'section' => 'returns',
            'name' => 'Мой возвратный',
            'filters' => [],
        ]);
        UserSearchPreset::create([
            'user_id' => $other->id,
            'section' => 'orders',
            'name' => 'Чужой поиск',
            'filters' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/search-presets/orders');
        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Мой поиск 1'], $names);
    }

    #[Test]
    public function index_returns_404_for_unknown_section(): void
    {
        $this->enablePresets();
        $this->actingAs($this->user)
            ->getJson('/cabinet/search-presets/unknown')
            ->assertNotFound();
    }

    #[Test]
    public function destroy_deletes_own_preset(): void
    {
        $this->enablePresets();

        $preset = UserSearchPreset::create([
            'user_id' => $this->user->id,
            'section' => 'orders',
            'name' => 'Удаляемый',
            'filters' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/cabinet/search-presets/'.$preset->id);
        $response->assertOk();
        $this->assertNull(UserSearchPreset::find($preset->id));
    }

    #[Test]
    public function destroy_does_not_delete_foreign_preset(): void
    {
        $this->enablePresets();

        $other = User::factory()->create();
        $foreign = UserSearchPreset::create([
            'user_id' => $other->id,
            'section' => 'orders',
            'name' => 'Чужой пресет',
            'filters' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/cabinet/search-presets/'.$foreign->id);
        $response->assertNotFound();
        $this->assertNotNull(UserSearchPreset::find($foreign->id));
    }
}
