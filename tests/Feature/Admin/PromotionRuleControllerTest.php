<?php

namespace Tests\Feature\Admin;

use App\Contracts\Promotion\PromoStockCheckerInterface;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Promotion\AlwaysAvailablePromoStock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Конструктор правил акций в админке (карточка promo-03).
 */
class PromotionRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Эти тесты про правила и тексты, а не про склад: до promo-07 движок
        // работал с заглушкой «всегда доступно», оставляем её здесь явно.
        // Проверки самого фонда живут в PromoStockServiceTest.
        $this->app->bind(PromoStockCheckerInterface::class, AlwaysAvailablePromoStock::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    /** Контент-менеджер: по сидеру у него только promotion-rules.view. */
    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('content-manager');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $conditionProduct = Product::factory()->create();
        $rewardProduct = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['is_defect' => false]);

        return array_merge([
            'name' => 'Lush 4 за 0 ₽ от 150 000 ₽',
            'promotion_id' => null,
            'mode' => 'info',
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'priority' => 10,
            'stackable' => true,
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => [
                        'products' => [$conditionProduct->id],
                        'categories' => [],
                        'with_descendants' => false,
                        'brands' => [],
                        'tags' => [],
                        'erp_promotions' => [],
                        'whole_cart' => false,
                    ],
                    'aggregate' => 'amount',
                    'operator' => '>=',
                    'value' => 150000,
                ]],
            ],
            'rewards' => [[
                'type' => 'fixed',
                'product_id' => $rewardProduct->id,
                'choices' => [],
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => 'accountable',
                'warehouse_id' => $warehouse->id,
                'multiply' => 'once',
                'max_multiplier' => 1,
                'optional' => false,
            ]],
            'audience' => ['region_ids' => [], 'user_ids' => [], 'manager_ids' => [], 'channels' => []],
            'limits' => ['per_client_total' => null, 'total' => null],
        ], $overrides);
    }

    // ────────────────────────────────────────────
    // Права
    // ────────────────────────────────────────────

    #[Test]
    public function admin_can_open_rules_list(): void
    {
        PromotionRule::factory()->create(['name' => 'Правило для списка']);

        $this->actingAs($this->admin())
            ->get('/admin/promotion-rules')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/PromotionRules/Index')
                ->has('rules.data', 1)
                ->where('rules.data.0.name', 'Правило для списка')
                ->has('rules.data.0.condition_summary')
                ->has('rules.data.0.reward_summary'));
    }

    /**
     * Сотрудник с доступом в админку, но без прав на правила акций:
     * роль без promotion-rules.* — иначе вход в /admin просто редиректит.
     */
    private function outsider(): User
    {
        $role = Role::firstOrCreate(['name' => 'promo-outsider', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function user_without_permission_cannot_open_rules_list(): void
    {
        $this->actingAs($this->outsider())->get('/admin/promotion-rules')->assertForbidden();
    }

    #[Test]
    public function content_manager_can_view_but_cannot_create_rules(): void
    {
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get('/admin/promotion-rules')->assertOk();
        $this->actingAs($viewer)->get('/admin/promotion-rules/create')->assertForbidden();
        $this->actingAs($viewer)->post('/admin/promotion-rules', $this->payload())->assertForbidden();
    }

    #[Test]
    public function content_manager_cannot_delete_rule(): void
    {
        $rule = PromotionRule::factory()->create();

        $this->actingAs($this->viewer())
            ->delete("/admin/promotion-rules/{$rule->id}")
            ->assertForbidden();
    }

    // ────────────────────────────────────────────
    // CRUD
    // ────────────────────────────────────────────

    #[Test]
    public function admin_can_create_rule(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $payload)
            ->assertRedirect();

        $rule = PromotionRule::query()->firstOrFail();

        $this->assertSame('Lush 4 за 0 ₽ от 150 000 ₽', $rule->name);
        $this->assertTrue($rule->is_active);
        $this->assertSame(10, $rule->priority);
        $this->assertSame(150000.0, (float) $rule->conditions['items'][0]['value']);
        // price_basis подставляется контроллером: в схеме он единственно возможный
        $this->assertSame('client_final', $rule->conditions['items'][0]['price_basis']);
        $this->assertCount(1, $rule->rewards);
    }

    #[Test]
    public function created_rule_materializes_participants(): void
    {
        $payload = $this->payload();
        $conditionProductId = $payload['conditions']['items'][0]['selector']['products'][0];

        $this->actingAs($this->admin())->post('/admin/promotion-rules', $payload);

        $rule = PromotionRule::query()->firstOrFail();

        $this->assertDatabaseHas('promotion_rule_product', [
            'promotion_rule_id' => $rule->id,
            'product_id' => $conditionProductId,
            'role' => PromotionRule::ROLE_CONDITION,
        ]);
    }

    #[Test]
    public function admin_can_update_rule(): void
    {
        $rule = PromotionRule::factory()->create();

        $this->actingAs($this->admin())
            ->put("/admin/promotion-rules/{$rule->id}", $this->payload(['name' => 'Новое название']))
            ->assertRedirect();

        $this->assertSame('Новое название', $rule->fresh()->name);
    }

    #[Test]
    public function admin_can_delete_rule_and_it_goes_to_archive(): void
    {
        $rule = PromotionRule::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/promotion-rules/{$rule->id}")
            ->assertRedirect(route('admin.promotion-rules.index'));

        $this->assertSoftDeleted('promotion_rules', ['id' => $rule->id]);
    }

    #[Test]
    public function edit_page_returns_form_payload_with_entity_names(): void
    {
        $product = Product::factory()->create(['name' => 'Lovense Lush 4']);
        $rule = PromotionRule::factory()->freeGift($product->id)->create();

        $this->actingAs($this->admin())
            ->get("/admin/promotion-rules/{$rule->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/PromotionRules/Edit')
                ->where('rule.product_names.'.$product->id, 'Lovense Lush 4')
                ->where('issue_mode_available', false)
                ->has('warehouses')
                ->has('regions'));
    }

    // ────────────────────────────────────────────
    // Валидация
    // ────────────────────────────────────────────

    #[Test]
    public function rule_with_empty_selector_is_rejected_in_russian(): void
    {
        $payload = $this->payload();
        $payload['conditions']['items'][0]['selector']['products'] = [];

        $response = $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $payload)
            ->assertSessionHasErrors('conditions');

        $this->assertStringContainsString(
            'не выбрано ни одного товара',
            session('errors')->first('conditions'),
        );

        $response->assertRedirect();
        $this->assertSame(0, PromotionRule::query()->count());
    }

    #[Test]
    public function per_threshold_reward_without_cap_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['rewards'][0]['multiply'] = 'per_threshold';
        $payload['conditions']['items'][0]['per_value'] = 50000;
        $payload['rewards'][0]['max_multiplier'] = 0;

        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $payload)
            ->assertSessionHasErrors('rewards');

        $this->assertStringContainsString('Потолок кратности', session('errors')->first('rewards'));
    }

    #[Test]
    public function choice_reward_with_single_product_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['rewards'][0]['type'] = 'choice';
        $payload['rewards'][0]['choices'] = [Product::factory()->create()->id];

        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $payload)
            ->assertSessionHasErrors('rewards');
    }

    #[Test]
    public function issue_mode_is_rejected_until_wave_two(): void
    {
        config()->set('promotions.issue_enabled', false);

        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $this->payload(['mode' => 'issue']))
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, PromotionRule::query()->count());
    }

    #[Test]
    public function issue_mode_is_accepted_once_enabled(): void
    {
        config()->set('promotions.issue_enabled', true);

        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $this->payload(['mode' => 'issue']))
            ->assertSessionHasNoErrors();

        $this->assertSame('issue', PromotionRule::query()->firstOrFail()->mode->value);
    }

    #[Test]
    public function period_end_before_start_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', $this->payload([
                'starts_at' => '2026-08-10 00:00',
                'ends_at' => '2026-08-01 00:00',
            ]))
            ->assertSessionHasErrors('ends_at');
    }

    /**
     * Форма раскладывает ошибки по вкладкам по корню ключа: `rewards.0.product_id`
     * ведёт на «Награды», `limits.total` — на «Аудиторию и лимиты». Ключ с чужим
     * корнем подсветит не ту вкладку, и редактор снова будет искать причину
     * вручную — поэтому набор корней зафиксирован тестом.
     *
     * @see resources/js/Admin/Pages/PromotionRules/components/RuleForm.jsx (FIELD_TABS)
     */
    #[Test]
    public function ошибки_валидации_ложатся_на_известные_форме_поля(): void
    {
        $known = [
            'name', 'promotion_id', 'mode', 'starts_at', 'ends_at', 'priority',
            'is_active', 'stackable', 'conditions', 'rewards', 'audience', 'limits',
        ];

        $this->actingAs($this->admin())
            ->post('/admin/promotion-rules', [
                'name' => '',
                'mode' => 'нет-такого-режима',
                'conditions' => [],
                'rewards' => [],
                'starts_at' => '2026-08-10 00:00',
                'ends_at' => '2026-08-01 00:00',
            ])
            ->assertSessionHasErrors();

        $keys = array_keys(session('errors')->getBag('default')->messages());
        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertContains(
                explode('.', $key)[0],
                $known,
                "Ключ ошибки «{$key}» не сопоставлен ни с одной вкладкой формы",
            );
        }
    }

    // ────────────────────────────────────────────
    // Предпросмотр
    // ────────────────────────────────────────────

    #[Test]
    public function preview_shows_aggregate_against_threshold(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create();

        $rule = PromotionRule::factory()
            ->amountThreshold(10000, [$product->id])
            ->freeGift($rewardProduct->id)
            ->create();

        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.promotion-rules.preview', $rule->id), [
                'source' => 'cart',
                'id' => $cart->id,
            ])
            ->assertOk();

        $response->assertJsonPath('preview.fired', false);
        $response->assertJsonPath('preview.conditions.0.value', 4000);
        $response->assertJsonPath('preview.conditions.0.target', 10000);
        $response->assertJsonPath('preview.conditions.0.remaining', 6000);
        $response->assertJsonPath('subject.type', 'cart');
        $this->assertNotEmpty($response->json('condition_lines'));
    }

    #[Test]
    public function preview_shows_reward_when_rule_fires(): void
    {
        $product = Product::factory()->create(['base_price' => 1000]);
        $rewardProduct = Product::factory()->create(['name' => 'Промо-товар']);

        $rule = PromotionRule::factory()
            ->amountThreshold(1000, [$product->id])
            ->freeGift($rewardProduct->id)
            ->create();

        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'item_type' => 'instock',
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.promotion-rules.preview', $rule->id), [
                'source' => 'cart',
                'id' => $cart->id,
            ])
            ->assertOk();

        $response->assertJsonPath('preview.fired', true);
        $response->assertJsonPath('preview.applied.0.product_id', $rewardProduct->id);
        $response->assertJsonPath('product_names.'.$rewardProduct->id, 'Промо-товар');
        // Правило выключено — предпросмотр обязан это показать, а не молчать
        $response->assertJsonPath('preview.is_active', false);
    }

    #[Test]
    public function preview_reports_missing_cart(): void
    {
        $rule = PromotionRule::factory()->create();

        $this->actingAs($this->admin())
            ->postJson(route('admin.promotion-rules.preview', $rule->id), [
                'source' => 'cart',
                'id' => 999999,
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Корзина с таким ID не найдена');
    }

    #[Test]
    public function preview_requires_view_permission(): void
    {
        $rule = PromotionRule::factory()->create();
        $cart = Cart::factory()->create();

        $this->actingAs($this->outsider())
            ->postJson(route('admin.promotion-rules.preview', $rule->id), [
                'source' => 'cart',
                'id' => $cart->id,
            ])
            ->assertForbidden();
    }

    // ────────────────────────────────────────────
    // Служебные действия
    // ────────────────────────────────────────────

    #[Test]
    public function match_count_returns_number_of_matching_products(): void
    {
        $products = Product::factory()->count(3)->create();

        $this->actingAs($this->admin())
            ->postJson(route('admin.promotion-rules.match-count'), [
                'selector' => ['products' => $products->pluck('id')->all()],
            ])
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('whole_cart', false);
    }

    /**
     * Вставка таблицы «артикул → кратность» из Excel: строки становятся
     * позициями условия, нераспознанное возвращается явно.
     */
    #[Test]
    public function sku_table_is_parsed_into_products_and_steps(): void
    {
        $first = Product::factory()->create(['sku' => 'LE-22']);
        $second = Product::factory()->create(['sku' => 'LE-60']);

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.promotion-rules.parse-sku-table'), [
                'text' => "LE-22\t1\nLE-60;2\nLE-999 6\n\nle-22 4",
            ])
            ->assertOk();

        $response->assertJsonCount(2, 'matched');
        $response->assertJsonPath('matched.0.product_id', $first->id);
        $response->assertJsonPath('matched.0.per_value', 1);
        $response->assertJsonPath('matched.1.product_id', $second->id);
        $response->assertJsonPath('matched.1.per_value', 2);
        // Дубль артикула не создаёт вторую позицию, неизвестный — виден
        $response->assertJsonPath('unknown', ['LE-999']);
    }

    #[Test]
    public function sku_table_without_step_defaults_to_every_piece(): void
    {
        Product::factory()->create(['sku' => 'LE-77']);

        $this->actingAs($this->admin())
            ->postJson(route('admin.promotion-rules.parse-sku-table'), ['text' => 'LE-77'])
            ->assertOk()
            ->assertJsonPath('matched.0.per_value', 1);
    }

    #[Test]
    public function rule_with_per_item_steps_is_saved(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $gift = Product::factory()->create();

        $payload = $this->payload([
            'conditions' => [
                'mode' => 'any',
                'items' => [
                    $this->conditionItem([$first->id], 4, 4),
                    $this->conditionItem([$second->id], 6, 6),
                ],
            ],
            'rewards' => [[
                'type' => 'fixed',
                'product_id' => $gift->id,
                'choices' => [],
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => 'accountable',
                'warehouse_id' => null,
                'multiply' => 'per_threshold',
                // Общий шаг не задаём — его берут позиции условия
                'max_multiplier' => 20,
                'optional' => false,
            ]],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.promotion-rules.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rule = PromotionRule::query()->latest('id')->firstOrFail();

        // JSON-хранение приводит 4.0 к 4, поэтому сравниваем по значению
        $this->assertEquals(4, $rule->conditions['items'][0]['per_value']);
        $this->assertEquals(6, $rule->conditions['items'][1]['per_value']);
    }

    /**
     * Шага в награде больше нет. Кратность «на каждые N» без шага хоть
     * в одном условии не выдала бы ничего — не сохраняем.
     */
    #[Test]
    public function per_threshold_without_condition_step_is_rejected(): void
    {
        $first = Product::factory()->create();
        $gift = Product::factory()->create();

        $payload = $this->payload([
            'conditions' => [
                'mode' => 'all',
                'items' => [$this->conditionItem([$first->id], 4)],
            ],
            'rewards' => [[
                'type' => 'fixed',
                'product_id' => $gift->id,
                'choices' => [],
                'quantity' => 1,
                'price' => 0,
                'promo_kind' => 'accountable',
                'warehouse_id' => null,
                'multiply' => 'per_threshold',
                'max_multiplier' => 20,
                'optional' => false,
            ]],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.promotion-rules.store'), $payload)
            ->assertSessionHasErrors('rewards');

        $this->assertSame(0, PromotionRule::query()->count());
    }

    /**
     * @param  int[]  $productIds
     * @return array<string, mixed>
     */
    private function conditionItem(array $productIds, float $value, ?float $perValue = null): array
    {
        return [
            'selector' => [
                'products' => $productIds,
                'categories' => [],
                'with_descendants' => false,
                'brands' => [],
                'tags' => [],
                'erp_promotions' => [],
                'whole_cart' => false,
            ],
            'aggregate' => 'quantity',
            'operator' => '>=',
            'value' => $value,
            'per_value' => $perValue,
        ];
    }

    #[Test]
    public function rebuild_recalculates_participants(): void
    {
        $product = Product::factory()->create();
        $rule = PromotionRule::factory()->amountThreshold(1000, [$product->id])->create();

        \DB::table('promotion_rule_product')->where('promotion_rule_id', $rule->id)->delete();

        $this->actingAs($this->admin())
            ->from("/admin/promotion-rules/{$rule->id}/edit")
            ->post(route('admin.promotion-rules.rebuild', $rule->id))
            ->assertRedirect();

        $this->assertDatabaseHas('promotion_rule_product', [
            'promotion_rule_id' => $rule->id,
            'product_id' => $product->id,
            'role' => PromotionRule::ROLE_CONDITION,
        ]);
    }

    // ────────────────────────────────────────────
    // Блок на странице акции
    // ────────────────────────────────────────────

    #[Test]
    public function promotion_edit_page_lists_its_rules(): void
    {
        $promotion = Promotion::factory()->create();
        PromotionRule::factory()->create([
            'promotion_id' => $promotion->id,
            'name' => 'Правило акции',
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/promotions/{$promotion->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rules', 1)
                ->where('rules.0.name', 'Правило акции')
                ->has('rules.0.condition_summary'));
    }
}
