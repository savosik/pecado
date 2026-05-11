<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI-генерация rich_content
    |--------------------------------------------------------------------------
    |
    | Настройки автоматической генерации Editor.js JSON-блоков для товаров
    | через OpenRouter. Срабатывает при открытии QuickView, если rich_content
    | у товара ещё пустой.
    */

    'enabled' => env('RICH_CONTENT_AI_ENABLED', true),

    'model' => env('RICH_CONTENT_AI_MODEL', 'google/gemini-2.5-flash'),

    'temperature' => (float) env('RICH_CONTENT_AI_TEMPERATURE', 0.6),

    'max_tokens' => (int) env('RICH_CONTENT_AI_MAX_TOKENS', 8000),

    'request_timeout' => (int) env('RICH_CONTENT_AI_REQUEST_TIMEOUT', 120),

    'min_description_length' => (int) env('RICH_CONTENT_AI_MIN_DESCRIPTION_LENGTH', 80),

    'max_attempts' => (int) env('RICH_CONTENT_AI_MAX_ATTEMPTS', 3),

    'failure_cooldown_hours' => (int) env('RICH_CONTENT_AI_FAILURE_COOLDOWN_HOURS', 24),

    'lock_seconds' => (int) env('RICH_CONTENT_AI_LOCK_SECONDS', 60),

    'schema_path' => env('RICH_CONTENT_AI_SCHEMA_PATH', base_path('docs/content-blocks-schema.json')),

    'allowed_block_types' => [
        'header',
        'paragraph',
        'list',
        'quote',
        'pullQuote',
        'delimiter',
        'table',
        'warning',
        'faq',
        'iconFeature',
        'stats',
        'steps',
        'timeline',
        'comparison',
        'tabs',
        'columns',
    ],
];
