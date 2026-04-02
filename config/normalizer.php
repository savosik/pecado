<?php

return [
    'enabled' => env('DATA_NORMALIZER_ENABLED', true),
    'api_key' => env('OPENROUTER_API_KEY'),
    'model'   => env('DATA_NORMALIZER_MODEL', 'anthropic/claude-sonnet-4-20250514'),
    'timeout' => 30, // секунд — более умная модель работает дольше
];
