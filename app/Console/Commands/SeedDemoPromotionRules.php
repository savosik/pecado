<?php

namespace App\Console\Commands;

use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Region;
use App\Models\Warehouse;
use App\Services\Promotion\PromotionRuleSchemaValidator;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Демо-акции для приёмки конструктора промо.
 *
 * Пять правил по числу механик, которые нужно проверить руками: подотчётный
 * подарок, пробник со склада «Москва подарки», подарок на выбор, отклоняемая
 * платная позиция и подарок с лимитом. У каждого правила **свой** триггерный
 * товар — иначе одна корзина срабатывала бы по всем пяти сразу, и разобрать,
 * что именно сломалось, было бы нельзя.
 *
 * Команда идемпотентна: правила ищутся по имени и переписываются целиком.
 * Товары подбираются из каталога по остатку в регионе, поэтому на разных
 * окружениях они будут разные — конкретный набор печатается в конце.
 *
 * Это данные для тестирования, не боевая настройка: имена правил начинаются
 * с «[Демо]», чтобы их было видно в списке и не жалко удалить.
 */
class SeedDemoPromotionRules extends Command
{
    use ConfirmableTrait;

    protected $signature = 'promo:seed-demo
                            {--region= : ID региона, по остаткам которого подбирать товары}
                            {--sample-stock=50 : Остаток пробника на складе «Москва подарки», если его там нет (0 — не проставлять)}
                            {--force : Выполнить без подтверждения в production}';

    protected $description = 'Создать демо-акции конструктора промо для ручного тестирования';

    /** Префикс имени — по нему правила находятся при повторном запуске. */
    private const PREFIX = '[Демо]';

    public function handle(PromotionRuleSchemaValidator $validator): int
    {
        if (! $this->confirmToProceed('Команда создаёт тестовые акции с реальными товарами')) {
            return self::FAILURE;
        }

        if (! PromotionRuleMode::issueAvailable()) {
            $this->warn('PROMO_ISSUE_ENABLED выключен: правила создадутся, но выдачу нужно включить флагом.');
        }

        $region = $this->resolveRegion();

        if ($region === null) {
            $this->error('Не найден регион с primary-складами — подбирать товары не по чему.');

            return self::FAILURE;
        }

        $this->info("Регион для подбора товаров: «{$region->name}» (id {$region->id}).");

        // 5 триггеров + 5 наград, одна из которых — выбор из двух товаров
        $products = $this->pickProducts($region, 12);

        if ($products->count() < 12) {
            $this->error("В регионе «{$region->name}» найдено только {$products->count()} товаров с остатком — нужно 12.");

            return self::FAILURE;
        }

        $sampleWarehouse = Warehouse::query()->promoSample()->first();

        if ($sampleWarehouse === null) {
            $this->error('Склад рекламных образцов не найден — акция с пробником не будет создана.');

            return self::FAILURE;
        }

        $p = $products->values();

        $sampleGift = $p[3];
        $this->ensureSampleStock($sampleWarehouse, $sampleGift);

        $definitions = [
            $this->accountableGift($p[0], $p[1]),
            $this->sampleGift($p[2], $sampleGift, $sampleWarehouse),
            $this->choiceGift($p[4], $p[5], $p[6]),
            $this->optionalPaidGift($p[7], $p[8]),
            $this->limitedGift($p[9], $p[10]),
        ];

        $rows = [];

        foreach ($definitions as $definition) {
            $rule = $this->upsert($definition, $validator);

            if ($rule === null) {
                return self::FAILURE;
            }

            $rows[] = [$rule->id, $rule->name, $definition['hint']];
        }

        $this->newLine();
        $this->table(['ID', 'Акция', 'Как проверить'], $rows);
        $this->newLine();
        $this->info('Готово. Правила активны и работают в режиме «Выдавать».');

        return self::SUCCESS;
    }

    // ────────────────────────────────────────────
    // Определения акций
    // ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function accountableGift(Product $trigger, Product $gift): array
    {
        return [
            'name' => self::PREFIX.' Подотчётный подарок: 3 шт. → подарок',
            'hint' => "{$trigger->sku} × 3 шт. → «{$gift->name}» бесплатно",
            'conditions' => $this->quantityCondition($trigger, 3),
            'rewards' => [$this->reward(productId: $gift->id, price: 0)],
            'limits' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleGift(Product $trigger, Product $gift, Warehouse $warehouse): array
    {
        return [
            'name' => self::PREFIX.' Пробник со склада «Москва подарки»: 2 шт. → образец',
            'hint' => "{$trigger->sku} × 2 шт. → пробник «{$gift->name}» отдельным заказом promo_sample",
            'conditions' => $this->quantityCondition($trigger, 2),
            'rewards' => [$this->reward(
                productId: $gift->id,
                price: 0,
                promoKind: PromoKind::SAMPLE,
                warehouseId: $warehouse->id,
            )],
            'limits' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choiceGift(Product $trigger, Product $first, Product $second): array
    {
        return [
            'name' => self::PREFIX.' Подарок на выбор: от 5 000 ₽ → один из двух',
            'hint' => "{$trigger->sku} на сумму от 5 000 ₽ → выбор: «{$first->name}» или «{$second->name}»",
            'conditions' => $this->amountCondition($trigger, 5000),
            'rewards' => [$this->reward(
                type: PromotionRule::REWARD_TYPE_CHOICE,
                productId: null,
                price: 0,
                choices: [$first->id, $second->id],
            )],
            'limits' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionalPaidGift(Product $trigger, Product $gift): array
    {
        return [
            'name' => self::PREFIX.' Отклоняемая позиция: 4 шт. → товар за 40 ₽',
            'hint' => "{$trigger->sku} × 4 шт. → «{$gift->name}» за 40 ₽ с кнопкой отказа",
            'conditions' => $this->quantityCondition($trigger, 4),
            // Отказ имеет смысл только у платной позиции: от бесплатной
            // отказываться незачем, и движок флаг у неё игнорирует
            'rewards' => [$this->reward(productId: $gift->id, price: 40, optional: true)],
            'limits' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function limitedGift(Product $trigger, Product $gift): array
    {
        return [
            'name' => self::PREFIX.' Ограниченный тираж: 2 шт. → подарок, всего 3 выдачи',
            'hint' => "{$trigger->sku} × 2 шт. → «{$gift->name}»; 1 раз на клиента, 3 выдачи всего",
            'conditions' => $this->quantityCondition($trigger, 2),
            'rewards' => [$this->reward(productId: $gift->id, price: 0)],
            'limits' => ['total' => 3, 'per_client_total' => 1],
        ];
    }

    // ────────────────────────────────────────────
    // Кирпичики конфигурации
    // ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function quantityCondition(Product $trigger, int $value): array
    {
        return ['mode' => 'all', 'items' => [[
            'selector' => ['products' => [$trigger->id]],
            'aggregate' => PromotionRule::AGGREGATE_QUANTITY,
            'operator' => '>=',
            'value' => $value,
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function amountCondition(Product $trigger, float $value): array
    {
        return ['mode' => 'all', 'items' => [[
            'selector' => ['products' => [$trigger->id]],
            'aggregate' => PromotionRule::AGGREGATE_AMOUNT,
            'price_basis' => 'client_final',
            'operator' => '>=',
            'value' => $value,
        ]]];
    }

    /**
     * @param  int[]|null  $choices
     * @return array<string, mixed>
     */
    private function reward(
        ?int $productId,
        float $price,
        string $type = PromotionRule::REWARD_TYPE_FIXED,
        ?array $choices = null,
        PromoKind $promoKind = PromoKind::ACCOUNTABLE,
        ?int $warehouseId = null,
        bool $optional = false,
    ): array {
        return [
            'type' => $type,
            'product_id' => $productId,
            'choices' => $choices,
            'quantity' => 1,
            'price' => $price,
            'promo_kind' => $promoKind->value,
            'warehouse_id' => $warehouseId,
            'multiply' => PromotionRule::MULTIPLY_ONCE,
            'max_multiplier' => 1,
            'optional' => $optional,
        ];
    }

    // ────────────────────────────────────────────
    // Сохранение
    // ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsert(array $definition, PromotionRuleSchemaValidator $validator): ?PromotionRule
    {
        $attributes = [
            'promotion_id' => null,
            'is_active' => true,
            'mode' => PromotionRuleMode::ISSUE,
            'starts_at' => null,
            'ends_at' => null,
            'priority' => 0,
            'stackable' => true,
            'conditions' => $definition['conditions'],
            'rewards' => $definition['rewards'],
            // Оба канала: механику надо проверить и в корзине, и в клиентском API
            'audience' => ['channels' => [PromotionRule::CHANNEL_SITE, PromotionRule::CHANNEL_API]],
            'limits' => $definition['limits'],
        ];

        $result = $validator->validate(array_merge($attributes, [
            'name' => $definition['name'],
            'mode' => PromotionRuleMode::ISSUE->value,
        ]));

        if (! $result['valid']) {
            $this->error("Правило «{$definition['name']}» не прошло валидацию:");

            foreach ($this->flatten($result['errors']) as $message) {
                $this->line('  — '.$message);
            }

            return null;
        }

        // Обсервер сам пересоберёт promotion_rule_product после сохранения
        return PromotionRule::updateOrCreate(['name' => $definition['name']], $attributes);
    }

    /**
     * @param  array<string, mixed>|list<string>  $errors
     * @return list<string>
     */
    private function flatten(array $errors): array
    {
        $flat = [];

        array_walk_recursive($errors, static function ($message) use (&$flat) {
            $flat[] = (string) $message;
        });

        return $flat;
    }

    // ────────────────────────────────────────────
    // Подбор данных
    // ────────────────────────────────────────────

    private function resolveRegion(): ?Region
    {
        $regionId = $this->option('region');

        if ($regionId !== null) {
            return Region::query()->find((int) $regionId);
        }

        // Первый регион, у которого есть primary-склады: без них остатки
        // не попадут в витрину и триггер не сработает
        $id = DB::table('region_warehouse')
            ->where('type', 'primary')
            ->orderBy('region_id')
            ->value('region_id');

        return $id !== null ? Region::query()->find((int) $id) : null;
    }

    /**
     * Товары с остатком на primary-складах региона, подороже — чтобы порог
     * по сумме в 5 000 ₽ набирался разумным количеством штук.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function pickProducts(Region $region, int $count): \Illuminate\Support\Collection
    {
        $warehouseIds = DB::table('region_warehouse')
            ->where('region_id', $region->id)
            ->where('type', 'primary')
            ->pluck('warehouse_id');

        $productIds = DB::table('product_warehouse')
            ->whereIn('warehouse_id', $warehouseIds)
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->having('total', '>=', 10)
            ->pluck('product_id');

        return Product::query()
            ->whereIn('id', $productIds)
            ->whereNotNull('sku')
            ->where('base_price', '>', 100)
            ->orderByDesc('base_price')
            ->limit($count)
            ->get();
    }

    /**
     * Пробник обязан лежать на рекламном складе, иначе движок его не выдаст:
     * фонд считается по складу награды. Остатки туда приходят из 1С, но на
     * тестовом окружении их может не быть — проставляем сами.
     */
    private function ensureSampleStock(Warehouse $warehouse, Product $product): void
    {
        $quantity = (int) $this->option('sample-stock');

        $existing = (int) DB::table('product_warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');

        if ($existing > 0) {
            $this->info("Пробник «{$product->name}»: остаток на складе «{$warehouse->name}» — {$existing} шт.");

            return;
        }

        if ($quantity <= 0) {
            $this->warn("Пробник «{$product->name}» не имеет остатка на складе «{$warehouse->name}» — акция не сработает.");

            return;
        }

        DB::table('product_warehouse')->updateOrInsert(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity' => $quantity],
        );

        $this->warn(
            "Пробнику «{$product->name}» проставлен тестовый остаток {$quantity} шт. на складе «{$warehouse->name}». "
            .'Следующая выгрузка остатков из 1С его перезапишет.'
        );
    }
}
