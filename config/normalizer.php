<?php

return [
    'enabled' => env('DATA_NORMALIZER_ENABLED', true),
    'model'   => env('DATA_NORMALIZER_MODEL', 'openai/gpt-4o-mini'),
    'timeout' => 10, // секунд
];
