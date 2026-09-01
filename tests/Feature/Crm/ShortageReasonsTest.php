<?php

namespace Tests\Feature\Crm;

use App\Enums\Shortage\ShortageReasonCategory;
use App\Models\OrderItem;
use App\Models\ShortageReason;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Справочник причин недоборов: его ведёт руководитель отдела.
 *
 * Предмет проверок — граница полномочий (менеджер выбирает причину, но список
 * не дописывает) и два запрета на удаление: заводские причины и причины,
 * которыми уже размечены строки. Оба существуют ради одного — чтобы сводки
 * за прошлые месяцы продолжали сходиться.
 */
class ShortageReasonsTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
    }

    #[Test]
    public function nine_default_reasons_come_with_the_migration(): void
    {
        $reasons = ShortageReason::query()->where('is_system', true)->get();

        $this->assertCount(9, $reasons);
        $this->assertTrue($reasons->every(fn (ShortageReason $reason) => $reason->is_active));

        $this->assertEqualsCanonicalizing([
            'Нет остатка при получении с сайта',
            'Увели из-под резерва позицию',
            'Товар не снабжён предзаказом',
            'Отменил склад по причине недостачи',
            'Отменил склад по причине дефектов',
            'Отменил менеджер по просьбе клиента',
            'Отменил клиент после сборки заказа',
            'Отменил менеджер вручную сам',
            'Ошибка учёта в 1С',
        ], $reasons->pluck('name')->all());

        // Категория заводской причины — не украшение: по ней считаются чипы.
        $this->assertSame(
            ShortageReasonCategory::ACCOUNTING,
            ShortageReason::query()->where('name', 'Ошибка учёта в 1С')->value('category'),
        );
    }

    #[Test]
    public function head_of_sales_adds_a_reason(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/shortage-reasons', [
                'name' => 'Не пришла поставка от поставщика',
                'category' => 'supply',
                'description' => 'Заказ поставщику размещён, но товар не приехал к дате отгрузки.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.label', 'Не пришла поставка от поставщика')
            ->assertJsonPath('data.category', 'supply')
            ->assertJsonPath('data.is_system', false);

        $this->assertDatabaseHas('shortage_reasons', [
            'name' => 'Не пришла поставка от поставщика',
            'category' => 'supply',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function manager_can_only_read_the_directory(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/crm/shortage-reasons', ['name' => 'Своя причина', 'category' => 'manager'])
            ->assertForbidden();

        $reason = ShortageReason::factory()->create();

        $this->actingAs($this->manager)
            ->patchJson("/crm/shortage-reasons/{$reason->id}", ['name' => 'Переименовал'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->deleteJson("/crm/shortage-reasons/{$reason->id}")
            ->assertForbidden();

        // Справочник при этом виден: без него выбирать причину было бы не из чего.
        $this->assertTrue($this->manager->can('crm-shortage-reasons.view'));
    }

    #[Test]
    public function duplicate_name_is_rejected(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/shortage-reasons', [
                'name' => 'Ошибка учёта в 1С',
                'category' => 'accounting',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function reason_can_be_renamed_and_switched_off(): void
    {
        $reason = ShortageReason::factory()->create(['name' => 'Черновая формулировка']);

        $this->actingAs($this->head)
            ->patchJson("/crm/shortage-reasons/{$reason->id}", [
                'name' => 'Позицию увёл другой заказ',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $reason->refresh();

        $this->assertSame('Позицию увёл другой заказ', $reason->name);
        $this->assertFalse($reason->is_active);
    }

    #[Test]
    public function factory_reason_cannot_be_deleted_only_switched_off(): void
    {
        $system = ShortageReason::query()->where('is_system', true)->firstOrFail();

        $this->actingAs($this->head)
            ->deleteJson("/crm/shortage-reasons/{$system->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertModelExists($system);
    }

    #[Test]
    public function reason_with_marked_lines_cannot_be_deleted(): void
    {
        $reason = ShortageReason::factory()->create();
        OrderItem::factory()->create([
            'cancelled' => true,
            'cancelled_at' => now(),
            'cancel_reason_id' => $reason->id,
        ]);

        $this->actingAs($this->head)
            ->deleteJson("/crm/shortage-reasons/{$reason->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertModelExists($reason);
    }

    #[Test]
    public function unused_own_reason_is_deleted(): void
    {
        $reason = ShortageReason::factory()->create();

        $this->actingAs($this->head)
            ->deleteJson("/crm/shortage-reasons/{$reason->id}")
            ->assertOk();

        $this->assertModelMissing($reason);
    }

    #[Test]
    public function directory_tab_shows_usage_counters(): void
    {
        $reason = ShortageReason::query()->where('name', 'Отменил склад по причине дефектов')->firstOrFail();
        OrderItem::factory()->count(2)->create([
            'cancelled' => true,
            'cancelled_at' => now(),
            'cancel_reason_id' => $reason->id,
        ]);

        $response = $this->actingAs($this->head)->get('/crm/shortages?tab=reasons');
        $usage = collect($response->viewData('page')['props']['reasonUsage'])->keyBy('id');

        $this->assertSame(2, $usage[$reason->id]['lines_count']);
        $this->assertSame(0, $usage[ShortageReason::query()->where('name', 'Ошибка учёта в 1С')->value('id')]['lines_count']);
    }
}
