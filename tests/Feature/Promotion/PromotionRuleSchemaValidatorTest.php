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
            'per_value' => null,
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

    public function test_per_threshold_requires_step_and_cap(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward([
            'multiply' => 'per_threshold',
            'per_value' => null,
            'max_multiplier' => null,
        ])]]);

        $result = $this->validator->validate($rule);
        $messages = $this->flatten($result['errors']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('нужен шаг больше нуля', $messages);
        $this->assertStringContainsString('обязателен потолок', $messages);
    }

    public function test_per_threshold_with_step_and_cap_passes(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward([
            'multiply' => 'per_threshold',
            'per_value' => 150000,
            'max_multiplier' => 3,
        ])]]);

        $result = $this->validator->validate($rule);

        $this->assertTrue($result['valid'], $this->flatten($result['errors']));
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

    public function test_sample_reward_can_be_saved_but_not_activated(): void
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

    public function test_reward_product_must_not_be_part_of_condition(): void
    {
        $rule = $this->rule(['rewards' => [$this->reward(['product_id' => $this->conditionProduct->id])]]);

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('входит в условие того же правила', $this->flatten($result['errors']));
    }

    public function test_reward_product_inside_condition_category_is_rejected(): void
    {
        $category = \App\Models\Category::factory()->create();
        $this->rewardProduct->update(['category_id' => $category->id]);

        $rule = $this->rule();
        $rule['conditions']['items'][0]['selector'] = ['categories' => [$category->id]];

        $result = $this->validator->validate($rule);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('входит в условие того же правила', $this->flatten($result['errors']));
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
