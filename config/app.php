<?php

declare(strict_types=1);

return [
    'name'  => $_ENV['APP_NAME'] ?? 'LitePlatform App',
    'env'   => $_ENV['APP_ENV'] ?? 'development',
    'debug' => (bool)($_ENV['APP_DEBUG'] ?? true),
    'url'   => $_ENV['APP_URL'] ?? 'http://localhost:8000',

    /*
    |--------------------------------------------------------------------------
    | Active Service Providers (Opt-in / Opt-out)
    |--------------------------------------------------------------------------
    | Enable or disable features simply by commenting/uncommenting providers.
    | If a library is not installed, the provider degrades gracefully without error.
    */
    'providers' => [
        App\Providers\DatabaseProvider::class, // LiteORM & PDO
        App\Providers\ViewProvider::class,     // LiteView (SSR / HTMX)
        App\Providers\ApiProvider::class,      // LiteApi (REST, CORS, JWT)
        App\Providers\AuditProvider::class,    // LiteAudit (Audit Trail)
        App\Providers\AdminProvider::class,    // LiteAdmin (Auto-CRUD Dashboard)
        App\Providers\QueueProvider::class,    // LiteQueue (Background Jobs)
        App\Providers\InertiaProvider::class,  // LiteInertia (React / Vue SPA),
    ],

    /*
    |--------------------------------------------------------------------------
    | LiteAdmin Auto-CRUD Dashboard Configuration
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'prefix' => '/admin',
        'entities' => [
            App\Entities\User::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API & Security Configuration
    |--------------------------------------------------------------------------
    */
    'api' => [
        'jwt_secret' => $_ENV['JWT_SECRET'] ?? 'default-jwt-secret-32-chars-long!',
        'cors' => [
            'allowed_origins' => ['*'],
        ],
    ],
];
