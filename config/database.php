<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    */
    'default' => $_ENV['DB_CONNECTION'] ?? 'sqlite',

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => __DIR__ . '/../' . ($_ENV['DB_DATABASE'] ?? 'storage/app.sqlite'),
            'prefix'   => '',
        ],

        'mysql' => [
            'driver'    => 'mysql',
            'host'      => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'      => (int)($_ENV['DB_PORT'] ?? 3306),
            'database'  => $_ENV['DB_DATABASE'] ?? 'lite_app',
            'username'  => $_ENV['DB_USERNAME'] ?? 'root',
            'password'  => $_ENV['DB_PASSWORD'] ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port'     => (int)($_ENV['DB_PORT'] ?? 5432),
            'database' => $_ENV['DB_DATABASE'] ?? 'lite_app',
            'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset'  => 'utf8',
            'prefix'   => '',
        ],
    ],
];
