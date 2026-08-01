<?php

namespace Tests\Feature\Promotion;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Promotion\PromotionRuleSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Валидация конфигурации правила акции: структура (JSON Schema) и смысл.
 * Все сообщения проверяются на русском — их видит маркетолог в конструкторе.
 */
class PromotionRuleSchemaValidatorTest extends TestCase
{
    use RefreshDatabase;

    private PromotionRuleSchemaValidator $validator;

    private Product $conditionProduct;

    private Product $rewardProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(PromotionRuleSchemaValidator::class);
        $this->conditionProduct = Product::factory()->create();
        $this->rewardProduct = Product::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => ['products' => [$this->conditionProduct->id]],
                    'aggregate' => 'amount',
                    'price_basis' => 'client_final',
                    'operator' => '>=',
                    'value' => 150000,
                ]],
            ],
            'rewards' => [$this->reward()],
            'audience' => null,
            'limits' => null,
            'is_active' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reward(array $overrides = []): array
    {
        return array_merge([
            'type' => 'fixed',
            'product_id' => $this->rewardProduct->id,
            'choices' => null,
            'quantity' => 1,
            'price' => 0,
            'promo_kind' => 'accountable',
            'warehouse_id' => null,
            'multiply' => 'once',
            'max_multiplier' => 1,
            'optional' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private function flatten(array $errors): string
    {
        return implode(' | ', array_merge(...array_values($errors) ?: [[]]));
    }

    public function test_valid_rule_passes(): void
    {
        $result = $this->validator->validate($this->rule());

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    public function test_aggregate_must_be_quantity_or_amount(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['aggregate'] = 'weight';

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Агрегат условия должен быть', $this->flatten($result['errors']));
    }

    public function test_price_basis_accepts_only_client_final(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['price_basis'] = 'base';

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('client_final', $this->flatten($result['errors']));
    }

    public function test_empty_selector_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['selector'] = [];

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('не выбрано ни одного товара', $this->flatten($result['errors']));
    }

    public function test_whole_cart_selector_is_allowed(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['selector'] = ['whole_cart' => true];

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    public function test_negative_price_is_rejected(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['price' => -1])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Промо-цена', $this->flatten($result['errors']));
    }

    public function test_price_with_three_decimals_is_rejected(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['price' => 10.555])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('двух знаков после запятой', $this->flatten($result['errors']));
    }

    public function test_kopeck_price_is_allowed(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['price' => 0.01])]]);

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    /**
     * Шага в награде больше нет: он живёт в условии. Награда «на каждые N»
     * без шага хоть в одном условии не выдалась бы ни разу — отклоняем.
     */
    public function test_per_threshold_requires_step_in_a_condition(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward([
            'multiply' => 'per_threshold',
            'max_multiplier' => null,
        ])]]);

        $result = $this->validator->validate($rule);
        $messages = $this->flatten($result['errors']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('требует шага хотя бы в одном условии', $messages);
        $this->assertStringContainsString('обязателен потолок', $messages);
    }

    public function test_per_threshold_with_condition_step_and_cap_passes(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward([
            'multiply' => 'per_threshold',
            'max_multiplier' => 3,
        ])]]);
        $rule['conditions']['items'][0]['per_value'] = 50000;

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    /**
     * Шаг у каждого артикула свой — по позиции условия на товар.
     */
    public function test_each_condition_carries_its_own_step(): void
    {
        $second = Product::factory()->create();

        $rule = $this->rule([
            'conditions' => [
                'mode' => 'any',
                'items' => [
                    [
                        'selector' => ['products' => [$this->conditionProduct->id]],
                        'aggregate' => 'quantity',
                        'price_basis' => 'client_final',
                        'operator' => '>=',
                        'value' => 4,
                        'per_value' => 4,
                    ],
                    [
                        'selector' => ['products' => [$second->id]],
                        'aggregate' => 'quantity',
                        'price_basis' => 'client_final',
                        'operator' => '>=',
                        'value' => 6,
                        'per_value' => 6,
                    ],
                ],
            ],
            'rewards' => [$this->reward([
                'multiply' => 'per_threshold',
                'max_multiplier' => 20,
            ])],
        ]);

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    /**
     * Шаг больше порога — та самая ловушка «сработало, но ничего не выдало».
     */
    public function test_condition_step_greater_than_threshold_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['aggregate'] = 'quantity';
        $rule['conditions']['items'][0]['value'] = 5;
        $rule['conditions']['items'][0]['per_value'] = 10;

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('шаг кратности', $this->flatten($result['errors']));
    }

    public function test_condition_step_requires_greater_or_equal_operator(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['operator'] = '<=';
        $rule['conditions']['items'][0]['per_value'] = 1000;

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('кратность работает только со сравнением', $this->flatten($result['errors']));
    }

    public function test_condition_step_must_be_positive_number(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['per_value'] = 0;

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Кратность позиции условия', $this->flatten($result['errors']));
    }

    public function test_choice_reward_requires_at_least_two_products(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward([
            'type' => 'choice',
            'product_id' => null,
            'choices' => [$this->rewardProduct->id],
        ])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('минимум два товара', $this->flatten($result['errors']));
    }

    public function test_choice_reward_with_two_products_passes(): void
    {
        $second = Product::factory()->create();

        $rule = $this->rule(['rewards' => [$this->reward([
            'type' => 'choice',
            'product_id' => null,
            'choices' => [$this->rewardProduct->id, $second->id],
        ])]]);

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    public function test_fixed_reward_requires_product(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['product_id' => null])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('нужно выбрать товар', $this->flatten($result['errors']));
    }

    public function test_unknown_warehouse_is_rejected(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['warehouse_id' => 999999])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('склад-источник не найден', $this->flatten($result['errors']));
    }

    public function test_defect_warehouse_cannot_be_promo_source(): void
    {
        $warehouse = Warehouse::factory()->create(['is_defect' => true]);

        $rule = $this->rule(['rewards' => [$this->reward(['warehouse_id' => $warehouse->id])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('склад некондиции', $this->flatten($result['errors']));
    }

    /**
     * Правило-черновик без склада сохраняется — маркетолог заполняет форму
     * по частям. Включить его без склада нельзя: пробник некуда пойти отбирать.
     */
    public function test_sample_reward_without_warehouse_can_be_saved_but_not_activated(): void
    {
        $saved = $this->validator->validate($this->rule([
            'rewards' => [$this->reward(['promo_kind' => 'sample'])],
        ]));

        $this->assertTrue($saved['valid'], $this->flatten($saved['errors']));

        $activated = $this->validator->validate($this->rule([
            'rewards' => [$this->reward(['promo_kind' => 'sample'])],
            'is_active' => true,
        ]));

        $this->assertFalse($activated['valid']);
        $this->assertStringContainsString('Москва реклама', $this->flatten($activated['errors']));
    }

    public function test_sample_reward_with_promo_warehouse_is_valid(): void
    {
        $warehouse = Warehouse::factory()->create([
            'name' => 'Москва реклама',
            'is_promo_sample' => true,
        ]);

        $result = $this->validator->validate($this->rule([
            'rewards' => [$this->reward(['promo_kind' => 'sample', 'warehouse_id' => $warehouse->id])],
            'is_active' => true,
        ]));

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    public function test_sample_reward_rejects_ordinary_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['name' => 'Основной']);

        $result = $this->validator->validate($this->rule([
            'rewards' => [$this->reward(['promo_kind' => 'sample', 'warehouse_id' => $warehouse->id])],
        ]));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('склада рекламных образцов', $this->flatten($result['errors']));
    }

    /**
     * «Каждый шестой такой же товар в подарок» — обычная механика, и запрещать её
     * нельзя. Промо-строки движок в агрегаты не берёт, так что подарок собственное
     * условие не подкручивает.
     */
    public function test_reward_product_may_be_part_of_condition(): void
    {
        $rule = $this->rule([
            'conditions' => [
                'mode' => 'all',
                'items' => [[
                    'selector' => ['products' => [$this->conditionProduct->id]],
                    'aggregate' => 'quantity',
                    'price_basis' => 'client_final',
                    'operator' => '>=',
                    'value' => 5,
                    'per_value' => 5,
                ]],
            ],
            'rewards' => [$this->reward([
                'product_id' => $this->conditionProduct->id,
                'multiply' => 'per_threshold',
                'max_multiplier' => 10,
            ])],
        ]);

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    public function test_reward_product_inside_condition_category_is_allowed(): void
    {
        $category = \App\Models\Category::factory()->create();
        $this->rewardProduct->update(['category_id' => $category->id]);

        $rule = $this->rule();
        $rule['conditions']['items'][0]['selector'] = ['categories' => [$category->id]];

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
    }

    public function test_unknown_channel_is_rejected(): void
    {
        $rule = $this->rule(['audience' => ['channels' => ['telegram']]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Каналы могут быть только', $this->flatten($result['errors']));
    }

    public function test_unknown_field_in_selector_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'][0]['selector'] = ['produkty' => [1]];

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('неизвестное поле', $this->flatten($result['errors']));
    }

    public function test_empty_condition_list_is_rejected(): void
    {
        $rule = $this->rule();
        $rule['conditions']['items'] = [];

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('хотя бы одно условие', $this->flatten($result['errors']));
    }

    public function test_assert_valid_throws_validation_exception(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['price' => -5])]]);

        try {
            $this->validator->assertValid($rule);
            $this->fail('Ожидалось исключение валидации.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rewards', $e->errors());
        }
    }
}
