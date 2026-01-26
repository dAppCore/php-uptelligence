<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core PHP Framework Configuration
    |--------------------------------------------------------------------------
    */

    'module_paths' => [
        app_path('Core'),
        app_path('Mod'),
        app_path('Website'),
    ],

    'services' => [
        'cache_discovery' => env('CORE_CACHE_DISCOVERY', true),
    ],

    'cdn' => [
        'enabled' => env('CDN_ENABLED', false),
        'driver' => env('CDN_DRIVER', 'bunny'),
    ],
];
