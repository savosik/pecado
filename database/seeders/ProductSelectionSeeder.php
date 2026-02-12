<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductSelection;
use Database\Seeders\Traits\GeneratesSeederImages;
use Illuminate\Database\Seeder;

class ProductSelectionSeeder extends Seeder
{
    use GeneratesSeederImages;

    public function run(): void
    {
        $allProducts = Product::pluck('id')->toArray();

        if (empty($allProducts)) {
            return;
        }

        $tmpDir = storage_path('app/selection-banners');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $selections = [
            [
                'name'              => '🆕 Новинки',
                'slug'              => 'novinki',
                'short_description' => 'Самые свежие поступления в нашем каталоге',
                'sort_order'        => 1,
                'filter'            => fn () => Product::where('is_new', true)->pluck('id')->toArray(),
                'colors'            => ['#8B1A4A', '#D4447C', '#FF6B9D'],
                'banner_title'      => 'НОВИНКИ СЕЗОНА',
                'banner_subtitle'   => 'Откройте для себя свежие поступления',
            ],
            [
                'name'              => '🔥 Хиты продаж',
                'slug'              => 'hity-prodazh',
                'short_description' => 'Товары, которые выбирают чаще всего',
                'sort_order'        => 2,
                'filter'            => fn () => Product::where('is_bestseller', true)->pluck('id')->toArray(),
                'colors'            => ['#1A1A2E', '#0F3460', '#E94560'],
                'banner_title'      => 'ХИТЫ ПРОДАЖ',
                'banner_subtitle'   => 'Самые популярные товары месяца',
            ],
            [
                'name'              => '💎 Рекомендуем',
                'slug'              => 'rekomenduem',
                'short_description' => 'Наш выбор для вас',
                'sort_order'        => 3,
                'filter'            => fn () => collect($allProducts)->shuffle()->take(8)->toArray(),
                'colors'            => ['#2D1B69', '#6C3483', '#A569BD'],
                'banner_title'      => 'МЫ РЕКОМЕНДУЕМ',
                'banner_subtitle'   => 'Эксклюзивные товары от лучших брендов',
            ],
        ];

        foreach ($selections as $data) {
            $selection = ProductSelection::create([
                'name'              => $data['name'],
                'slug'              => $data['slug'],
                'short_description' => $data['short_description'],
                'sort_order'        => $data['sort_order'],
                'is_active'         => true,
                'show_on_home'      => true,
            ]);

            $productIds = ($data['filter'])();

            // Первые 6 товаров — featured (показываются на главной в табах)
            $syncData = [];
            foreach ($productIds as $index => $productId) {
                $syncData[$productId] = ['featured' => $index < 6];
            }
            $selection->products()->sync($syncData);

            // Генерируем desktop-баннер (широкий, узкий по высоте)
            $desktopPath = $this->generateGdImage(
                $tmpDir,
                $data['banner_title'],
                $data['banner_subtitle'],
                $data['colors'],
                "selection_{$selection->id}_desktop.png",
                1920,
                300
            );

            // Генерируем mobile-баннер (уже, но повыше относительно ширины)
            $mobilePath = $this->generateGdImage(
                $tmpDir,
                $data['banner_title'],
                $data['banner_subtitle'],
                $data['colors'],
                "selection_{$selection->id}_mobile.png",
                750,
                200
            );

            if ($desktopPath && file_exists($desktopPath)) {
                $selection->addMedia($desktopPath)
                    ->preservingOriginal()
                    ->toMediaCollection('desktop');
            }

            if ($mobilePath && file_exists($mobilePath)) {
                $selection->addMedia($mobilePath)
                    ->preservingOriginal()
                    ->toMediaCollection('mobile');
            } elseif ($desktopPath && file_exists($desktopPath)) {
                $selection->addMedia($desktopPath)
                    ->preservingOriginal()
                    ->toMediaCollection('mobile');
            }

            $this->command?->info("✓ Подборка «{$data['name']}» — баннеры созданы");
        }

        $this->command?->info('Создано подборок: ' . count($selections));
    }
}
