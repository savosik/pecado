<?php

use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\News;
use App\Models\Product;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    */

    'driver' => env('SCOUT_DRIVER', 'algolia'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => env('SCOUT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            Product::class => [
                'searchableAttributes' => [
                    'sku',             // 1. Артикул (высший приоритет)
                    'code',            // 2. Код товара
                    'barcodes',        // 3. Штрих-коды
                    'name',            // 4. Название (точный матч)
                    'brand',           // 5. Бренд
                    'category',        // 6. Категория
                    'name_translit',   // 7. Транслит
                    'name_cyrillic',   // 8. Обратный транслит
                    'name_layout',     // 9. Альтернативная раскладка
                    'brand_translit',  // 10. Бренд (транслит)
                    'brand_cyrillic',  // 11. Бренд (кириллица)
                    'brand_layout',    // 12. Бренд (раскладка)
                    'description',     // 13. Описание (самый низкий приоритет)
                ],
                'filterableAttributes' => [
                    'brand',
                    'category',
                ],
                'sortableAttributes' => [
                    'base_price',
                    'created_at',
                    'name',
                ],
            ],
            Category::class => [
                'searchableAttributes' => [
                    'name',
                    'name_translit',
                    'name_cyrillic',
                    'name_layout',
                ],
            ],
            Brand::class => [
                'searchableAttributes' => [
                    'name',
                    'name_translit',
                    'name_cyrillic',
                    'name_layout',
                ],
            ],
            Article::class => [
                'searchableAttributes' => [
                    'title',
                    'title_translit',
                    'title_cyrillic',
                    'title_layout',
                    'short_description',
                ],
            ],
            News::class => [
                'searchableAttributes' => [
                    'title',
                    'title_translit',
                    'title_cyrillic',
                    'title_layout',
                ],
            ],
            Media::class => [
                'searchableAttributes' => [
                    'owner_name',       // 1. Название владельца (товар, статья, бренд...)
                    'owner_code',       // 2. Код товара
                    'owner_sku',        // 3. Артикул товара
                    'owner_brand',      // 4. Бренд
                    'owner_category',   // 5. Категория
                    'owner_description',// 6. Описание владельца
                    'name',             // 7. Название медиафайла
                    'file_name',        // 8. Имя файла
                    'tags',             // 9. Теги
                ],
                'filterableAttributes' => [
                    'mime_type',
                    'mime_type_group',
                    'collection_name',
                    'model_type',
                ],
                'sortableAttributes' => [
                    'id',
                ],
            ],
        ],
    ],

];
