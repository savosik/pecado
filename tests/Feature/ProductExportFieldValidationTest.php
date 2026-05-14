<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Валидация контроллера должна пропускать ВСЕ модификаторы, которые
 * понимает ProductExportService — иначе UI отправляет правильные данные,
 * Laravel выбрасывает неизвестные ключи через $request->validate(),
 * и пользователь молча теряет настройки.
 */
class ProductExportFieldValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabinet_store_accepts_all_new_modifiers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('cabinet.product-exports.store'), [
                'name' => 'Test partner',
                'format' => 'csv',
                'filters' => [],
                'fields' => [
                    [
                        'key' => 'created_at',
                        'label' => 'created',
                        'modifiers' => ['format' => 'd.m.Y H:i:s'],
                    ],
                    [
                        'key' => 'base_price',
                        'label' => 'start_price',
                        'modifiers' => ['integer_if_whole' => true],
                    ],
                    [
                        'key' => 'external_id',
                        'label' => 'short_uuid',
                        'modifiers' => ['substring_start' => 0, 'substring_length' => 16],
                    ],
                    [
                        'key' => 'category_path',
                        'label' => 'cat',
                        'modifiers' => ['source_separator' => 'slash', 'separator' => 'slash_tight'],
                    ],
                ],
                'is_active' => true,
            ]);

        $response->assertRedirect();

        $export = ProductExport::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($export);

        $fields = $export->fields;
        // Все модификаторы должны быть сохранены — ничего не отброшено
        $this->assertSame('d.m.Y H:i:s', $fields[0]['modifiers']['format']);
        $this->assertTrue($fields[1]['modifiers']['integer_if_whole']);
        $this->assertSame(16, $fields[2]['modifiers']['substring_length']);
        $this->assertSame('slash', $fields[3]['modifiers']['source_separator']);
        $this->assertSame('slash_tight', $fields[3]['modifiers']['separator']);
    }

    public function test_cabinet_preview_accepts_alias_key_with_hash(): void
    {
        $user = User::factory()->create();
        Product::factory()->create(['name' => 'Тест']);

        $response = $this->actingAs($user)
            ->postJson(route('cabinet.product-exports.preview'), [
                'fields' => [
                    ['key' => 'name', 'label' => 'name1'],
                    ['key' => 'name#alt', 'label' => 'name2'],
                ],
                'filters' => [],
            ]);

        $response->assertOk();
        // Обе колонки должны вернуться отдельно
        $row = $response->json('data.0');
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('name#alt', $row);
    }

    public function test_admin_store_accepts_substring_modifier(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $client = User::factory()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.product-exports.store'), [
                'name' => 'Admin test',
                'format' => 'csv',
                'client_user_id' => $client->id,
                'filters' => [],
                'fields' => [
                    [
                        'key' => 'category.external_id',
                        'label' => 'cat_short',
                        'modifiers' => ['substring_length' => 16],
                    ],
                ],
                'is_active' => true,
            ]);

        $response->assertRedirect();
        $export = ProductExport::where('user_id', $admin->id)->latest()->first();
        $this->assertSame(16, $export->fields[0]['modifiers']['substring_length']);
    }
}
