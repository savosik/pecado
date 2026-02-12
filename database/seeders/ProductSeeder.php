<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Создаём бренды
        $brands = collect([
            'Satisfyer', 'Lelo', 'We-Vibe', 'Shunga', 'Obsessive',
            'System JO', 'Durex', 'Fun Factory',
        ])->map(fn ($name) => Brand::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name]
        ));

        // Товары
        $products = [
            ['name' => 'Массажёр Satisfyer Pro 2', 'brand' => 'Satisfyer', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Вибратор Lelo Sona 2', 'brand' => 'Lelo', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Набор We-Vibe Sync', 'brand' => 'We-Vibe', 'is_new' => false, 'is_bestseller' => true],
            ['name' => 'Массажное масло Shunga', 'brand' => 'Shunga', 'is_new' => false, 'is_bestseller' => false],
            ['name' => 'Комплект Obsessive Bondea', 'brand' => 'Obsessive', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Лубрикант System JO H2O', 'brand' => 'System JO', 'is_new' => false, 'is_bestseller' => true],
            ['name' => 'Презервативы Durex Elite', 'brand' => 'Durex', 'is_new' => false, 'is_bestseller' => true],
            ['name' => 'Вибратор Fun Factory Stronic', 'brand' => 'Fun Factory', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Кольцо Satisfyer Rings', 'brand' => 'Satisfyer', 'is_new' => false, 'is_bestseller' => false],
            ['name' => 'Свеча массажная Shunga', 'brand' => 'Shunga', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Костюм Obsessive Secretary', 'brand' => 'Obsessive', 'is_new' => false, 'is_bestseller' => true],
            ['name' => 'Гель-смазка Durex Play', 'brand' => 'Durex', 'is_new' => false, 'is_bestseller' => false],
            ['name' => 'Стимулятор Lelo Hugo', 'brand' => 'Lelo', 'is_new' => true, 'is_bestseller' => true],
            ['name' => 'Набор We-Vibe Discover', 'brand' => 'We-Vibe', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Спрей System JO Prolonger', 'brand' => 'System JO', 'is_new' => false, 'is_bestseller' => false],
            ['name' => 'Вибромассажёр Fun Factory Volta', 'brand' => 'Fun Factory', 'is_new' => false, 'is_bestseller' => true],
            ['name' => 'Пеньюар Obsessive Charms', 'brand' => 'Obsessive', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Насадка Satisfyer Multifun', 'brand' => 'Satisfyer', 'is_new' => false, 'is_bestseller' => false],
            ['name' => 'Массажёр Lelo Siri 3', 'brand' => 'Lelo', 'is_new' => true, 'is_bestseller' => false],
            ['name' => 'Крем Shunga Dragon', 'brand' => 'Shunga', 'is_new' => false, 'is_bestseller' => true],
        ];

        foreach ($products as $data) {
            $brand = $brands->firstWhere('name', $data['brand']);

            Product::firstOrCreate(
                ['slug' => Str::slug($data['name'])],
                [
                    'name'           => $data['name'],
                    'slug'           => Str::slug($data['name']),
                    'brand_id'       => $brand?->id,
                    'is_new'         => $data['is_new'],
                    'is_bestseller'  => $data['is_bestseller'],
                    'base_price'     => rand(500, 15000),
                    'description'    => 'Описание товара ' . $data['name'],
                    'sku'            => 'SKU-' . strtoupper(Str::random(6)),
                ]
            );
        }
    }
}
