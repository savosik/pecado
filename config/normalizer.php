<?php

return [
    'enabled' => env('DATA_NORMALIZER_ENABLED', true),
    'api_key' => env('OPENROUTER_API_KEY'),

    // HTTP-прокси для запросов к OpenRouter. На проде OpenRouter отдаёт 403 на
    // прямые запросы, поэтому они идут через контейнер outbound-proxy (SSH-туннель до VPS).
    // Пусто — ходим напрямую (dev, локальная разработка).
    'proxy' => env('OPENROUTER_PROXY'),

    'model' => env('DATA_NORMALIZER_MODEL', 'openai/gpt-4o'),

    // Общая чат-модель OpenRouter (плюсы/минусы товара и прочие мелкие запросы).
    'chat_model' => env('OPENROUTER_MODEL', 'google/gemini-2.0-flash-001'),

    'timeout' => 30, // секунд — более умная модель работает дольше
];
