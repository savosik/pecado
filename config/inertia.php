<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    */

    'ssr' => [

        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    'ensure_pages_exist' => false,

    'page_paths' => [

        resource_path('js/Pages'),

    ],

    'page_extensions' => [

        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',

    ],

    'use_script_element_for_initial_page' => (bool) env('INERTIA_USE_SCRIPT_ELEMENT_FOR_INITIAL_PAGE', false),

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Панели (/admin, /crm) резолвятся вместе с префиксом в имени компонента:
    | 'Crm/Pages/Clients/Index' → resources/js/Crm/Pages/Clients/Index.jsx
    | (см. resolve() в resources/js/app.jsx). Поэтому к дефолтному js/Pages
    | добавлен корень js — без него assertInertia()->component() для панелей
    | падает с «page component file does not exist».
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [

            resource_path('js/Pages'),
            resource_path('js'),

        ],

        'page_extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    'history' => [

        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),

    ],

];
