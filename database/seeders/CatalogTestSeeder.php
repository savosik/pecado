<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CatalogTestSeeder — наполняет каталог тестовыми данными для проверки фасетных фильтров.
 *
 * Создаёт бренды, категории, атрибуты с значениями, товары с привязками.
 */
class CatalogTestSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Бренды ───
        $brandNames = ['Lelo', 'Satisfyer', 'We-Vibe', 'Fun Factory', 'Womanizer',
                        'Fleshlight', 'Tenga', 'Doc Johnson', 'Pipedream', 'System JO',
                        'Swiss Navy', 'Shunga'];

        $brands = collect($brandNames)->map(fn ($name) =>
            Brand::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'short_description' => "Бренд $name — качество и инновации.",
                    'external_id' => Str::uuid()->toString(),
                ]
            )
        );

        $this->command->info("Создано/найдено брендов: {$brands->count()}");

        // ─── Категории (дерево) ───
        $categoryTree = [
            'Бельё' => ['Бюстгальтеры', 'Трусики', 'Корсеты', 'Чулки'],
            'Косметика' => ['Массажные масла', 'Лубриканты', 'Ароматические свечи'],
            'Аксессуары' => ['Маски', 'Наручники', 'Чокеры'],
            'Игрушки' => ['Вибраторы', 'Стимуляторы', 'Массажёры'],
        ];

        $allCategories = collect();

        foreach ($categoryTree as $parentName => $children) {
            $parent = Category::firstOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'slug' => Str::slug($parentName)]
            );
            $allCategories->push($parent);

            foreach ($children as $childName) {
                $child = Category::firstOrCreate(
                    ['slug' => Str::slug($childName)],
                    ['name' => $childName, 'slug' => Str::slug($childName), 'parent_id' => $parent->id]
                );
                $allCategories->push($child);
            }
        }

        // Перестроим дерево nested set
        Category::fixTree();

        $this->command->info("Создано/найдено категорий: {$allCategories->count()}");

        // ─── Атрибуты ───
        $attributeData = [
            'Цвет' => ['Чёрный', 'Красный', 'Розовый', 'Белый', 'Фиолетовый', 'Синий', 'Зелёный'],
            'Размер' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'Материал' => ['Силикон', 'Кожа', 'Кружево', 'Латекс', 'Хлопок', 'Полиэстер', 'Нейлон', 'Сатин', 'Шёлк', 'Неопрен', 'ABS-пластик'],
        ];

        $attrValueMap = []; // attribute_id => [value_id, ...]

        foreach ($attributeData as $attrName => $values) {
            $attribute = DB::table('attributes')->where('slug', Str::slug($attrName))->first();
            if (! $attribute) {
                $attrId = DB::table('attributes')->insertGetId([
                    'name' => $attrName,
                    'slug' => Str::slug($attrName),
                    'type' => 'select',
                    'is_filterable' => true,
                    'sort_order' => match ($attrName) {
                        'Цвет' => 1,
                        'Размер' => 2,
                        'Материал' => 3,
                    },
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $attrId = $attribute->id;
            }

            $valueIds = [];
            foreach ($values as $i => $val) {
                $existing = DB::table('attribute_values')
                    ->where('attribute_id', $attrId)
                    ->where('value', $val)
                    ->first();

                if ($existing) {
                    $valueIds[] = $existing->id;
                } else {
                    $valueIds[] = DB::table('attribute_values')->insertGetId([
                        'attribute_id' => $attrId,
                        'value' => $val,
                        'sort_order' => $i + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $attrValueMap[$attrId] = $valueIds;
        }

        $this->command->info('Создано атрибутов: ' . count($attrValueMap));

        // ─── Товары ───
        $productCount = 50;
        $childCategories = $allCategories->filter(fn ($c) => $c->parent_id !== null)->values();
        $brandIds = $brands->pluck('id')->toArray();
        $catIds = $childCategories->pluck('id')->toArray();

        // Если нет дочерних категорий, используем все
        if (empty($catIds)) {
            $catIds = $allCategories->pluck('id')->toArray();
        }

        $attrIds = array_keys($attrValueMap);

        $products = collect();
        for ($i = 0; $i < $productCount; $i++) {
            $product = Product::create([
                'name' => fake('ru_RU')->words(rand(2, 4), true),
                'slug' => Str::slug(fake('ru_RU')->words(rand(2, 4), true)) . '-' . Str::random(4),
                'sku' => 'SKU-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'base_price' => fake()->randomFloat(2, 100, 15000),
                'category_id' => fake()->randomElement($catIds),
                'brand_id' => fake()->randomElement($brandIds),
                'external_id' => Str::uuid()->toString(),
                'is_new' => fake()->boolean(20),
                'is_bestseller' => fake()->boolean(15),
                'description' => fake('ru_RU')->paragraphs(2, true),
                'short_description' => fake('ru_RU')->sentence(),
            ]);
            $products->push($product);

            // Привязать 1-3 атрибут-значений
            $numAttrs = rand(1, min(3, count($attrIds)));
            $selectedAttrIds = fake()->randomElements($attrIds, $numAttrs);

            foreach ($selectedAttrIds as $attrId) {
                $valueId = fake()->randomElement($attrValueMap[$attrId]);
                DB::table('product_attribute_values')->insertOrIgnore([
                    'product_id' => $product->id,
                    'attribute_id' => $attrId,
                    'attribute_value_id' => $valueId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info("Создано товаров: {$products->count()}");
        $this->command->info('CatalogTestSeeder завершён!');
    }
}
