<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hybrid Search
    |--------------------------------------------------------------------------
    |
    | Настройки гибридного поиска Meilisearch.
    | Гибридный поиск сочетает полнотекстовый (keyword) и семантический
    | (vector) поиск для более релевантных результатов.
    |
    */

    'hybrid' => [
        // Включить/выключить гибридный поиск
        'enabled' => env('SEARCH_HYBRID_ENABLED', false),

        // Имя embedder-а в Meilisearch
        'embedder' => env('SEARCH_HYBRID_EMBEDDER', 'openrouter'),

        // Баланс между полнотекстовым и семантическим поиском
        // 0.0 = только полнотекстовый, 1.0 = только семантический
        // 0.3 = 70% полнотекст + 30% семантика (рекомендовано)
        'semantic_ratio' => (float) env('SEARCH_SEMANTIC_RATIO', 0.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedder Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки REST embedder-а для OpenRouter API (OpenAI-совместимый).
    |
    */

    'embedder' => [
        'url' => env('MEILISEARCH_EMBEDDER_URL', 'https://openrouter.ai/api/v1/embeddings'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('MEILISEARCH_EMBEDDER_MODEL', 'openai/text-embedding-3-small'),
        'dimensions' => (int) env('MEILISEARCH_EMBEDDER_DIMENSIONS', 1536),
        'document_template_max_bytes' => 800,
    ],

];
