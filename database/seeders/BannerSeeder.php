<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Banner;
use App\Models\News;
use Database\Seeders\Traits\GeneratesSeederImages;
use Illuminate\Database\Seeder;

class BannerSeeder extends Seeder
{
    use GeneratesSeederImages;

    /**
     * Сидирование баннеров для главной страницы.
     * Изображения генерируются через GD (trait GeneratesSeederImages).
     */
    public function run(): void
    {
        $tmpDir = storage_path('app/banners');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Найти связанные сущности для полиморфных ссылок
        $article = Article::where('is_published', true)->first();
        $news = News::where('is_published', true)->first();

        if (!$news) {
            $news = News::create([
                'title'                => 'Открытие нового сезона',
                'slug'                 => 'otkrytie-novogo-sezona',
                'detailed_description' => '<p>Мы рады объявить о начале нового сезона в нашем магазине!</p>',
                'is_published'         => true,
                'published_at'         => now(),
            ]);
        }

        $banners = [
            [
                'title'          => 'Весенняя коллекция',
                'subtitle'       => 'Новые поступления уже в каталоге',
                'is_active'      => true,
                'sort_order'     => 1,
                'linkable_type'  => $article ? Article::class : null,
                'linkable_id'    => $article?->id,
                'colors'         => ['#8B1A4A', '#D4447C', '#FF6B9D'],
            ],
            [
                'title'          => 'Скидки до -30%',
                'subtitle'       => 'Только в этом месяце на все категории',
                'is_active'      => true,
                'sort_order'     => 2,
                'linkable_type'  => News::class,
                'linkable_id'    => $news->id,
                'colors'         => ['#1A1A2E', '#0F3460', '#E94560'],
            ],
            [
                'title'          => 'Новинки сезона',
                'subtitle'       => 'Эксклюзивные товары от лучших брендов',
                'is_active'      => true,
                'sort_order'     => 3,
                'linkable_type'  => null,
                'linkable_id'    => null,
                'colors'         => ['#2D1B69', '#6C3483', '#A569BD'],
            ],
        ];

        foreach ($banners as $data) {
            $banner = Banner::create([
                'title'         => $data['title'],
                'is_active'     => $data['is_active'],
                'sort_order'    => $data['sort_order'],
                'linkable_type' => $data['linkable_type'],
                'linkable_id'   => $data['linkable_id'],
            ]);

            // Генерируем desktop-изображение (широкое горизонтальное)
            $desktopPath = $this->generateGdImage(
                $tmpDir,
                $data['title'],
                $data['subtitle'],
                $data['colors'],
                "banner_{$banner->id}_desktop.png",
                1920,
                520
            );

            // Генерируем mobile-изображение (узкое, ближе к квадрату)
            $mobilePath = $this->generateGdImage(
                $tmpDir,
                $data['title'],
                $data['subtitle'],
                $data['colors'],
                "banner_{$banner->id}_mobile.png",
                750,
                400
            );

            if ($desktopPath && file_exists($desktopPath)) {
                $banner->addMedia($desktopPath)
                    ->preservingOriginal()
                    ->toMediaCollection('desktop');
            }

            if ($mobilePath && file_exists($mobilePath)) {
                $banner->addMedia($mobilePath)
                    ->preservingOriginal()
                    ->toMediaCollection('mobile');
            } elseif ($desktopPath && file_exists($desktopPath)) {
                $banner->addMedia($desktopPath)
                    ->preservingOriginal()
                    ->toMediaCollection('mobile');
            }

            $linkInfo = $data['linkable_type']
                ? " → " . class_basename($data['linkable_type']) . " #{$data['linkable_id']}"
                : '';

            $this->command?->info("✓ «{$data['title']}»{$linkInfo}");
        }

        $this->command?->info('Создано баннеров: ' . count($banners));
    }
}
