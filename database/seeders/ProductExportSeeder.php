<?php

namespace Database\Seeders;

use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductExportSeeder extends Seeder
{
    /**
     * Все статические поля экспорта с максимальным набором модификаторов.
     */
    protected function getAllFields(): array
    {
        // Статические поля
        $fields = [
            // ─── Основные ───
            ['key' => 'id', 'label' => 'ID товара'],
            ['key' => 'name', 'label' => 'Наименование'],
            ['key' => 'sku', 'label' => 'Артикул'],
            ['key' => 'code', 'label' => 'Код товара'],
            ['key' => 'barcode', 'label' => 'Штрихкод (основной)'],
            ['key' => 'base_price', 'label' => 'Базовая цена', 'modifiers' => ['currency_id' => null]],
            ['key' => 'recommended_price', 'label' => 'Рекомендованная цена'],
            ['key' => 'slug', 'label' => 'URL-slug'],
            ['key' => 'url', 'label' => 'URL'],
            ['key' => 'tnved', 'label' => 'Код ТН ВЭД'],
            ['key' => 'external_id', 'label' => 'Внешний ID'],
            ['key' => 'description', 'label' => 'Описание'],
            ['key' => 'short_description', 'label' => 'Краткое описание'],
            ['key' => 'meta_title', 'label' => 'Meta Title'],
            ['key' => 'meta_description', 'label' => 'Meta Description'],
            ['key' => 'description_plain', 'label' => 'Описание (без тегов)'],
            ['key' => 'short_description_plain', 'label' => 'Краткое описание (без тегов)'],

            // ─── Флаги ───
            ['key' => 'is_new', 'label' => 'Новинка', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'is_bestseller', 'label' => 'Бестселлер', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'is_marked', 'label' => 'Маркировка', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'is_liquidation', 'label' => 'Ликвидация', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],
            ['key' => 'for_marketplaces', 'label' => 'Для маркетплейсов', 'modifiers' => ['true_value' => 'Да', 'false_value' => 'Нет']],

            // ─── Бренд ───
            ['key' => 'brand.name', 'label' => 'Название бренда'],
            ['key' => 'brand.slug', 'label' => 'Slug бренда'],
            ['key' => 'brand.category', 'label' => 'Категория бренда'],

            // ─── Модель ───
            ['key' => 'model.name', 'label' => 'Модель (название)'],
            ['key' => 'model.code', 'label' => 'Модель (код)'],

            // ─── Категории ───
            ['key' => 'categories.name', 'label' => 'Категории', 'modifiers' => ['separator' => ', ']],
            ['key' => 'category_path', 'label' => 'Путь категорий'],

            // ─── Сертификаты ───
            ['key' => 'certificates.name', 'label' => 'Сертификаты', 'modifiers' => ['separator' => ', ']],

            // ─── Размерная сетка ───
            ['key' => 'size_chart.name', 'label' => 'Размерная сетка'],

            // ─── Складские остатки ───
            ['key' => 'warehouses.name', 'label' => 'Склады', 'modifiers' => ['separator' => ', ']],
            ['key' => 'warehouses.pivot.quantity', 'label' => 'Остатки по складам', 'modifiers' => ['separator' => ', ']],
            ['key' => 'total_stock', 'label' => 'Суммарный остаток'],

            // ─── Штрихкоды ───
            ['key' => 'barcodes.barcode', 'label' => 'Все штрихкоды', 'modifiers' => ['separator' => ', ']],

            // ─── Медиа ───
            ['key' => 'main_image', 'label' => 'Главное изображение'],
            ['key' => 'additional_images', 'label' => 'Доп. изображения', 'modifiers' => ['separator' => '; ']],
            ['key' => 'all_images', 'label' => 'Все изображения', 'modifiers' => ['separator' => '; ']],
            ['key' => 'video', 'label' => 'Видео'],

            // ─── Пользовательские (по клиенту) ───
            ['key' => 'discounted_price', 'label' => 'Цена со скидкой', 'modifiers' => ['currency_id' => null]],
            ['key' => 'discount_percentage', 'label' => 'Процент скидки'],
            ['key' => 'user_stock_available', 'label' => 'Остаток (основной)'],
            ['key' => 'user_stock_preorder', 'label' => 'Остаток (предзаказ)'],
            ['key' => 'client_region', 'label' => 'Регион клиента'],

            // ─── Служебные ───
            ['key' => 'created_at', 'label' => 'Дата создания'],
            ['key' => 'updated_at', 'label' => 'Дата обновления'],
        ];

        // ─── Динамические атрибуты (по slug из БД) ───
        $attributes = \App\Models\Attribute::orderBy('id')->get();
        foreach ($attributes as $attribute) {
            $fields[] = [
                'key' => "attribute.{$attribute->slug}",
                'label' => $attribute->name,
            ];
        }

        return $fields;
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminUser = User::where('is_admin', true)->first();

        if (!$adminUser) {
            $this->command->warn('Администратор не найден. Пропускаем ProductExportSeeder.');
            return;
        }

        $clientUser = User::where('is_admin', false)->first();
        $clientUserId = $clientUser?->id ?? $adminUser->id;

        $allFields = $this->getAllFields();

        // Профили по форматам
        $profiles = [
            [
                'name' => 'Полная выгрузка (JSON)',
                'format' => 'json',
                'fields' => $allFields,
                'filters' => ['logic' => 'and', 'conditions' => []],
            ],
            [
                'name' => 'Полная выгрузка (CSV)',
                'format' => 'csv',
                'fields' => $allFields,
                'filters' => ['logic' => 'and', 'conditions' => []],
            ],
            [
                'name' => 'Полная выгрузка (XML)',
                'format' => 'xml',
                'fields' => $allFields,
                'filters' => ['logic' => 'and', 'conditions' => []],
            ],
            [
                'name' => 'Полная выгрузка (Excel)',
                'format' => 'xls',
                'fields' => $allFields,
                'filters' => ['logic' => 'and', 'conditions' => []],
            ],
        ];

        foreach ($profiles as $profile) {
            ProductExport::updateOrCreate(
                [
                    'user_id' => $adminUser->id,
                    'name' => $profile['name'],
                ],
                [
                    'client_user_id' => $clientUserId,
                    'format' => $profile['format'],
                    'fields' => $profile['fields'],
                    'filters' => $profile['filters'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Создано ' . count($profiles) . ' профилей выгрузки товаров.');
    }
}
