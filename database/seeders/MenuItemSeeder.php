<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // ================================================================
            // Header — из navLinks в UserHeader.jsx
            // ================================================================
            ['title' => 'Каталог',    'url' => '/products',     'icon' => 'LuGrid2X2',    'location' => 'header', 'sort_order' => 10],
            ['title' => 'Акции',      'url' => '/promotions',   'icon' => null,            'location' => 'header', 'sort_order' => 20],
            ['title' => 'Новости',    'url' => '/news',         'icon' => 'LuNewspaper',   'location' => 'header', 'sort_order' => 30],
            ['title' => 'Статьи',     'url' => '/articles',     'icon' => 'LuFileText',    'location' => 'header', 'sort_order' => 40],
            ['title' => 'О брендах',  'url' => '/brand-stories','icon' => 'LuBadge',       'location' => 'header', 'sort_order' => 50],
            ['title' => 'FAQ',        'url' => '/faq',          'icon' => 'LuCircleHelp',  'location' => 'header', 'sort_order' => 60],
            ['title' => 'Где купить', 'url' => '/where-to-buy', 'icon' => 'LuMapPin',      'location' => 'header', 'sort_order' => 70],

            // ================================================================
            // Footer — группа «О компании» (companyLinks в UserFooter.jsx)
            // Иконок в исходных данных нет
            // ================================================================
            ['title' => 'О компании', 'url' => '/about',    'location' => 'footer', 'footer_group' => 'company', 'sort_order' => 10],
            ['title' => 'Карьера',    'url' => '/careers',  'location' => 'footer', 'footer_group' => 'company', 'sort_order' => 20],
            ['title' => 'Новости',    'url' => '/news',     'location' => 'footer', 'footer_group' => 'company', 'sort_order' => 30],
            ['title' => 'Статьи',     'url' => '/articles', 'location' => 'footer', 'footer_group' => 'company', 'sort_order' => 40],

            // ================================================================
            // Footer — группа «Покупателям» (buyerLinks в UserFooter.jsx)
            // Иконок в исходных данных нет
            // ================================================================
            ['title' => 'FAQ',        'url' => '/faq',          'location' => 'footer', 'footer_group' => 'buyers', 'sort_order' => 10],
            ['title' => 'Где купить', 'url' => '/where-to-buy', 'location' => 'footer', 'footer_group' => 'buyers', 'sort_order' => 20],
            ['title' => 'Акции',      'url' => '/promotions',   'location' => 'footer', 'footer_group' => 'buyers', 'sort_order' => 30],
        ];

        foreach ($items as $item) {
            MenuItem::firstOrCreate(
                ['title' => $item['title'], 'url' => $item['url'], 'location' => $item['location']],
                array_merge([
                    'icon' => null,
                    'badge_text' => null,
                    'badge_color' => null,
                    'footer_group' => null,
                    'is_published' => true,
                    'open_in_new_tab' => false,
                ], $item)
            );
        }

        $this->command->info('Создано ' . MenuItem::count() . ' пунктов меню.');
    }
}
