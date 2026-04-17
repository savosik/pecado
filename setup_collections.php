<?php

use App\Models\ProductSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$warehouseId = 2; // Москва основной

$collections = [
    [
        'name' => 'Для незабываемого вечера',
        'short_description' => 'Сногсшибательное белье, платья и боди, в которых вы будете неотразимы. Создайте романтическую атмосферу.',
        'categories' => ['Эротические платья, юбки', 'Боди и комбинезоны', 'Комплекты одежды и белья Ж'],
    ],
    [
        'name' => 'Идеальные лубриканты',
        'short_description' => 'Увлажняющие и согревающие смазки, а также съедобные гели для самых страстных моментов.',
        'categories' => ['Гели и смазки для вагинального секса', 'Съедобные гели и смазки', 'Гели и смазки для анального секса'],
    ],
    [
        'name' => 'БДСМ и игры для двоих',
        'short_description' => 'Наручники, оковы и эротические игры, которые добавят остроты и незабываемых впечатлений в вашу спальню.',
        'categories' => ['Наручники, манжеты', 'Оковы и поножи', 'Эротические подарки, игры и сувениры'],
    ],
    [
        'name' => 'Хиты: мужские мастурбаторы',
        'short_description' => 'Реалистичные мастурбаторы для максимального удовольствия. Самые популярные модели среди мужчин.',
        'categories' => ['Реалистичные мастурбаторы', 'Реалистичные фаллоимитаторы'],
    ],
    [
        'name' => 'Новый уровень стимуляции',
        'short_description' => 'Вакуумные стимуляторы клитора и вибраторы, которые открывают новые горизонты наслаждения.',
        'categories' => ['Вибраторы с клиторальным стимулятором', 'Вакуумные стимуляторы клитора', 'Нереалистичные вибраторы', 'Реалистичные вибраторы'],
    ],
];

foreach ($collections as $idx => $data) {
    echo "Creating collection: {$data['name']}\n";
    $slug = Str::slug($data['name']);

    // Check if sluggable exists, otherwise create unique
    $exists = ProductSelection::where('slug', $slug)->exists();
    if ($exists) {
        $slug = $slug.'-'.rand(100, 999);
    }

    $selection = ProductSelection::firstOrCreate(
        ['name' => $data['name']],
        [
            'slug' => $slug,
            'short_description' => $data['short_description'],
            'is_active' => true,
            'show_on_home' => true,
            'sort_order' => $idx + 1,
        ]
    );

    // Ensure it's active and shown if it already existed
    $selection->update([
        'is_active' => true,
        'show_on_home' => true,
        'sort_order' => $idx + 1,
    ]);

    // Find products in stock at warehouse 2 for these categories
    $productIds = DB::table('products as p')
        ->join('categories as c', 'p.category_id', '=', 'c.id')
        ->join('product_warehouse as pw', 'pw.product_id', '=', 'p.id')
        ->where('pw.warehouse_id', $warehouseId)
        ->where('pw.quantity', '>', 0)
        ->whereIn('c.name', $data['categories'])
        ->inRandomOrder()
        ->limit(10)
        ->pluck('p.id')
        ->toArray();

    // Sync with featured=true
    $syncData = [];
    foreach ($productIds as $pid) {
        $syncData[$pid] = ['featured' => true];
    }
    $selection->products()->syncWithoutDetaching($syncData);

    echo '  Added/Synced '.count($productIds)." featured products.\n";
}

echo "Done.\n";
