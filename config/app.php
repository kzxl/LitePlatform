<?php

declare(strict_types=1);

$envBool = static function (string $key, bool $default): bool {
    if (!array_key_exists($key, $_ENV)) {
        return $default;
    }

    return filter_var($_ENV[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
};

$appEnv = (string)($_ENV['APP_ENV'] ?? 'development');
$appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost:8000'), '/');

return [
    'name'  => $_ENV['APP_NAME'] ?? 'LitePlatform App',
    'env'   => $appEnv,
    'debug' => $envBool('APP_DEBUG', $appEnv !== 'production'),
    'url'   => $appUrl,

    /*
    |--------------------------------------------------------------------------
    | Active Service Providers (Opt-in / Opt-out)
    |--------------------------------------------------------------------------
    | Enable or disable features simply by commenting/uncommenting providers.
    | If a library is not installed, the provider degrades gracefully without error.
    */
    'providers' => [
        App\Providers\DatabaseProvider::class, // LiteORM & PDO
        App\Providers\CacheProvider::class,    // LiteCache (FileOpCache / DB / PageCache)
        App\Providers\ViewProvider::class,     // LiteView (SSR / HTMX)
        App\Providers\SeoProvider::class,      // Built-in SEO (Meta, JSON-LD, Sitemap, Robots, 301)
        App\Providers\ApiProvider::class,      // LiteApi (REST, CORS, JWT)
        App\Providers\AuditProvider::class,    // LiteAudit (Audit Trail)
        App\Providers\AdminProvider::class,    // LiteAdmin (Auto-CRUD Dashboard)
        App\Providers\QueueProvider::class,    // LiteQueue (Background Jobs)
        App\Providers\InertiaProvider::class,  // LiteInertia (React / Vue SPA)
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy Pipeline (Disable on single-domain Shared Hosting sites)
    |--------------------------------------------------------------------------
    */
    'tenancy' => [
        'enabled' => $envBool('TENANCY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache & Full-Page HTML Caching (LiteCache)
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'driver'     => $_ENV['CACHE_DRIVER'] ?? 'file',
        'page_cache' => $envBool('PAGE_CACHE_ENABLED', false),
        'page_ttl'   => (int)($_ENV['PAGE_CACHE_TTL'] ?? 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO Defaults (Meta, OpenGraph, JSON-LD, Sitemap, 301 Redirects)
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'site_name'           => $_ENV['APP_NAME'] ?? 'LitePlatform App',
        'default_description' => $_ENV['SEO_DESCRIPTION'] ?? 'Ultra-lightweight PHP & MySQL web application built with LitePlatform.',
        'default_og_image'    => $_ENV['SEO_OG_IMAGE'] ?? null,
        'twitter_handle'      => $_ENV['SEO_TWITTER'] ?? null,
        'locale'              => $_ENV['SEO_LOCALE'] ?? 'vi_VN',
        'sitemap_ttl'         => (int)($_ENV['SEO_SITEMAP_TTL'] ?? 3600),
        'redirects'           => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration (CSRF, Signed Cookies, Headers)
    |--------------------------------------------------------------------------
    */
    'security' => [
        'csrf_enabled'  => $envBool('CSRF_ENABLED', true),
        'csrf_secret'   => $_ENV['CSRF_SECRET'] ?? $_ENV['APP_KEY'] ?? 'csrf-secret-key-for-stateless-tokens',
        'admin_ttl'     => (int)($_ENV['ADMIN_SESSION_TTL'] ?? 28800),
        'cookie_secure' => str_starts_with($appUrl, 'https://'),
        'hsts_enabled'  => $appEnv === 'production' && str_starts_with($appUrl, 'https://'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Active Sovereign Feature Modules (Universe Architecture v4.0)
    |--------------------------------------------------------------------------
    | Self-contained domain feature modules with auto-service & route registration.
    */
    'modules' => [
        App\Modules\Inventory\InventoryModule::class,
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
                    \App\Entities\Product::class,
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
