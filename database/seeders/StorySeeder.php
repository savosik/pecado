<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\News;
use App\Models\Story;
use App\Models\StorySlide;
use Database\Seeders\Traits\GeneratesSeederImages;
use Illuminate\Database\Seeder;

class StorySeeder extends Seeder
{
    use GeneratesSeederImages;

    /**
     * Сидирование stories со слайдами для главной страницы.
     */
    public function run(): void
    {
        // Подготовить директорию для временных изображений
        $tmpDir = storage_path('app/stories');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Связанные сущности для полиморфных ссылок
        $news = News::where('is_published', true)->first();
        $articles = Article::where('is_published', true)->take(3)->get();

        $storiesData = [
            // ────────────────────────────────
            // Story 1: Весенняя коллекция
            // ────────────────────────────────
            [
                'name'         => 'Весенняя коллекция',
                'slug'         => 'spring-collection',
                'is_active'    => true,
                'is_published' => true,
                'sort_order'   => 1,
                'slides'       => [
                    [
                        'title'      => 'Новая весенняя коллекция',
                        'content'    => 'Откройте для себя лучшие новинки сезона',
                        'button_text' => 'Смотреть каталог',
                        'button_url'  => '/products',
                        'duration'    => 5000,
                        'sort_order'  => 1,
                        'colors'      => ['#8B1A4A', '#D4447C', '#FF6B9D'],
                        'linkable'    => null,
                    ],
                    [
                        'title'      => 'Скидки до 30%',
                        'content'    => 'Только в этом месяце на весеннюю коллекцию',
                        'button_text' => null,
                        'button_url'  => null,
                        'duration'    => 4000,
                        'sort_order'  => 2,
                        'colors'      => ['#D4447C', '#FF6B9D', '#FFB3D1'],
                        'linkable'    => null,
                    ],
                    [
                        'title'      => 'Бесплатная доставка',
                        'content'    => 'При заказе от 3000 ₽',
                        'button_text' => 'Перейти к покупкам',
                        'button_url'  => '/products',
                        'duration'    => 5000,
                        'sort_order'  => 3,
                        'colors'      => ['#FF6B9D', '#FFB3D1', '#FFE0EB'],
                        'linkable'    => null,
                    ],
                ],
            ],

            // ────────────────────────────────
            // Story 2: Новости и события
            // ────────────────────────────────
            [
                'name'         => 'Новости',
                'slug'         => 'news-highlights',
                'is_active'    => true,
                'is_published' => true,
                'sort_order'   => 2,
                'slides'       => [
                    [
                        'title'      => 'Открытие нового сезона',
                        'content'    => 'Читайте последние новости нашего магазина',
                        'button_text' => 'Читать подробнее',
                        'button_url'  => $news ? "/news/{$news->slug}" : '/news',
                        'duration'    => 5000,
                        'sort_order'  => 1,
                        'colors'      => ['#1A1A2E', '#16213E', '#0F3460'],
                        'linkable'    => $news ? ['type' => News::class, 'id' => $news->id] : null,
                    ],
                    [
                        'title'      => 'Следите за обновлениями',
                        'content'    => 'Подписывайтесь на нашу рассылку',
                        'button_text' => 'Все новости',
                        'button_url'  => '/news',
                        'duration'    => 4000,
                        'sort_order'  => 2,
                        'colors'      => ['#16213E', '#0F3460', '#533483'],
                        'linkable'    => null,
                    ],
                ],
            ],

            // ────────────────────────────────
            // Story 3: Полезные статьи
            // ────────────────────────────────
            [
                'name'         => 'Полезные статьи',
                'slug'         => 'useful-articles',
                'is_active'    => true,
                'is_published' => true,
                'sort_order'   => 3,
                'slides'       => array_merge(
                    $articles->map(function ($article, $index) {
                        $colorSets = [
                            ['#2D1B69', '#6C3483', '#A569BD'],
                            ['#6C3483', '#A569BD', '#D2B4DE'],
                            ['#1B4332', '#2D6A4F', '#52B788'],
                        ];
                        $colors = $colorSets[$index % count($colorSets)];

                        return [
                            'title'       => mb_substr($article->title, 0, 40),
                            'content'     => 'Читайте в нашем блоге',
                            'button_text' => 'Читать статью',
                            'button_url'  => "/articles/{$article->slug}",
                            'duration'    => 5000,
                            'sort_order'  => $index + 1,
                            'colors'      => $colors,
                            'linkable'    => ['type' => Article::class, 'id' => $article->id],
                        ];
                    })->toArray(),
                ),
            ],

            // ────────────────────────────────
            // Story 4: О нас
            // ────────────────────────────────
            [
                'name'         => 'О Pecado',
                'slug'         => 'about-pecado',
                'is_active'    => true,
                'is_published' => true,
                'sort_order'   => 4,
                'slides'       => [
                    [
                        'title'       => 'Добро пожаловать в Pecado',
                        'content'     => 'Премиальный магазин для взрослых',
                        'button_text' => null,
                        'button_url'  => null,
                        'duration'    => 4000,
                        'sort_order'  => 1,
                        'colors'      => ['#4A0E0E', '#8B1A1A', '#C0392B'],
                        'linkable'    => null,
                    ],
                    [
                        'title'       => 'Только оригинальная продукция',
                        'content'     => 'Мы работаем напрямую с производителями',
                        'button_text' => null,
                        'button_url'  => null,
                        'duration'    => 5000,
                        'sort_order'  => 2,
                        'colors'      => ['#8B1A1A', '#C0392B', '#E74C3C'],
                        'linkable'    => null,
                    ],
                    [
                        'title'       => 'Конфиденциальность гарантирована',
                        'content'     => 'Анонимная упаковка и доставка',
                        'button_text' => null,
                        'button_url'  => null,
                        'duration'    => 5000,
                        'sort_order'  => 3,
                        'colors'      => ['#2C3E50', '#34495E', '#5D6D7E'],
                        'linkable'    => null,
                    ],
                    [
                        'title'       => 'Быстрая доставка',
                        'content'     => 'По всей России от 1 дня',
                        'button_text' => 'Перейти в каталог',
                        'button_url'  => '/products',
                        'duration'    => 6000,
                        'sort_order'  => 4,
                        'colors'      => ['#1A5276', '#2980B9', '#5DADE2'],
                        'linkable'    => null,
                    ],
                ],
            ],
        ];

        // ────────────────────────────────
        // Дополнительные stories для тестирования скроллинга
        // ────────────────────────────────
        $extraStories = [
            ['name' => 'Летняя распродажа',  'colors' => [['#FF6B35', '#F7931E', '#FFD23F'], ['#F7931E', '#FFD23F', '#FFEC44']]],
            ['name' => 'Ночь скидок',        'colors' => [['#0D0221', '#0A1128', '#1F1F3D'], ['#1F1F3D', '#3A0088', '#6A00F4']]],
            ['name' => 'Подарки',             'colors' => [['#B5179E', '#7209B7', '#560BAD'], ['#560BAD', '#480CA8', '#3A0CA3']]],
            ['name' => 'Для него',            'colors' => [['#264653', '#2A9D8F', '#E9C46A'], ['#2A9D8F', '#E9C46A', '#F4A261']]],
            ['name' => 'Для неё',             'colors' => [['#F72585', '#B5179E', '#7209B7'], ['#B5179E', '#7209B7', '#560BAD']]],
            ['name' => 'Премиум',             'colors' => [['#1A1A2E', '#16213E', '#0F3460'], ['#0F3460', '#E94560', '#FF6B6B']]],
            ['name' => 'Массажёры',           'colors' => [['#00B4D8', '#0096C7', '#0077B6'], ['#0077B6', '#023E8A', '#03045E']]],
            ['name' => 'Бестселлеры',         'colors' => [['#E63946', '#A8201A', '#780116'], ['#780116', '#530011', '#2B0009']]],
            ['name' => 'Новый бренд',         'colors' => [['#606C38', '#283618', '#DDA15E'], ['#283618', '#DDA15E', '#BC6C25']]],
            ['name' => 'Романтика',           'colors' => [['#FF0A54', '#FF477E', '#FF7096'], ['#FF477E', '#FF7096', '#FF85A1']]],
            ['name' => 'Уход за телом',       'colors' => [['#588157', '#3A5A40', '#344E41'], ['#3A5A40', '#344E41', '#2D3A2D']]],
            ['name' => 'Аксессуары',          'colors' => [['#7B2D8E', '#9C27B0', '#CE93D8'], ['#9C27B0', '#CE93D8', '#E1BEE7']]],
            ['name' => 'Косметика',           'colors' => [['#D32F2F', '#C62828', '#B71C1C'], ['#C62828', '#B71C1C', '#880E4F']]],
            ['name' => 'Белье',               'colors' => [['#AD1457', '#C2185B', '#D81B60'], ['#C2185B', '#D81B60', '#E91E63']]],
            ['name' => 'Игры для двоих',      'colors' => [['#311B92', '#4527A0', '#512DA8'], ['#4527A0', '#512DA8', '#5E35B1']]],
            ['name' => 'Ароматы',             'colors' => [['#BF360C', '#D84315', '#E64A19'], ['#D84315', '#E64A19', '#F4511E']]],
            ['name' => 'VIP клуб',            'colors' => [['#FFD700', '#B8860B', '#8B6914'], ['#B8860B', '#8B6914', '#6B5100']]],
            ['name' => 'Рекомендации',        'colors' => [['#00695C', '#00796B', '#00897B'], ['#00796B', '#00897B', '#009688']]],
            ['name' => 'Тренды 2026',         'colors' => [['#4A148C', '#6A1B9A', '#7B1FA2'], ['#6A1B9A', '#7B1FA2', '#8E24AA']]],
            ['name' => 'Доставка за 1 день',  'colors' => [['#004D40', '#00695C', '#00796B'], ['#00695C', '#00796B', '#00897B']]],
        ];

        foreach ($extraStories as $idx => $extra) {
            $sortOrder = 5 + $idx;
            $storiesData[] = [
                'name'         => $extra['name'],
                'slug'         => 'story-' . ($sortOrder),
                'is_active'    => true,
                'is_published' => true,
                'sort_order'   => $sortOrder,
                'slides'       => [
                    [
                        'title'       => $extra['name'],
                        'content'     => 'Узнайте больше в нашем магазине',
                        'button_text' => 'Подробнее',
                        'button_url'  => '/products',
                        'duration'    => 5000,
                        'sort_order'  => 1,
                        'colors'      => $extra['colors'][0],
                        'linkable'    => null,
                    ],
                    [
                        'title'       => 'Только в Pecado',
                        'content'     => 'Эксклюзивные предложения каждый день',
                        'button_text' => 'В каталог',
                        'button_url'  => '/products',
                        'duration'    => 4000,
                        'sort_order'  => 2,
                        'colors'      => $extra['colors'][1],
                        'linkable'    => null,
                    ],
                ],
            ];
        }

        foreach ($storiesData as $storyData) {
            $story = Story::create([
                'name'         => $storyData['name'],
                'slug'         => $storyData['slug'],
                'is_active'    => $storyData['is_active'],
                'is_published' => $storyData['is_published'],
                'sort_order'   => $storyData['sort_order'],
            ]);

            foreach ($storyData['slides'] as $slideData) {
                $slide = StorySlide::create([
                    'story_id'      => $story->id,
                    'title'         => $slideData['title'],
                    'content'       => $slideData['content'],
                    'button_text'   => $slideData['button_text'],
                    'button_url'    => $slideData['button_url'],
                    'linkable_type' => $slideData['linkable']['type'] ?? null,
                    'linkable_id'   => $slideData['linkable']['id'] ?? null,
                    'duration'      => $slideData['duration'],
                    'sort_order'    => $slideData['sort_order'],
                ]);

                // Генерировать изображение-заглушку через GD
                $imagePath = $this->generateGdImage(
                    $tmpDir,
                    $slideData['title'],
                    $slideData['content'] ?? '',
                    $slideData['colors'],
                    "story_{$story->id}_slide_{$slide->id}.png",
                    720,
                    1280
                );

                if ($imagePath && file_exists($imagePath)) {
                    $slide->addMedia($imagePath)
                        ->toMediaCollection('default');
                }
            }

            $slideCount = count($storyData['slides']);
            $this->command?->info("✓ Story «{$storyData['name']}» — {$slideCount} слайдов");
        }

        $this->command?->info('Создано stories: ' . count($storiesData));
    }
}
