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
    'name'          => $_ENV['APP_NAME'] ?? 'LitePlatform App',
    'env'           => $appEnv,
    'debug'         => $envBool('APP_DEBUG', $appEnv !== 'production'),
    'url'           => $appUrl,
    'base_url_path' => (string)($_ENV['APP_BASE_PATH'] ?? ''),

    /*
    |--------------------------------------------------------------------------
    | Active Service Providers (Opt-in / Opt-out)
    |--------------------------------------------------------------------------
    | Enable or disable features simply by commenting/uncommenting providers.
    | If a library is not installed, the provider degrades gracefully without error.
    */
    'providers' => [
        Lite\Framework\Providers\DatabaseProvider::class, // LiteORM & PDO
        Lite\Framework\Providers\CacheProvider::class,    // LiteCache (FileOpCache / DB / PageCache)
        Lite\Framework\Providers\ViewProvider::class,     // LiteView (SSR / HTMX)
        Lite\Framework\Providers\I18nProvider::class,     // Multi-language UI (lang/*.php, @lang, /lang/{locale})
        Lite\Framework\Providers\SeoProvider::class,      // Built-in SEO (Meta, JSON-LD, Sitemap, Robots, 301)
        Lite\Framework\Providers\CmsProvider::class,      // CMS Micro-Kit (SiteSettings, MediaManager, Blog Sitemap)
        Lite\Framework\Providers\MailProvider::class,     // Zero-dep Mailer (SMTP / Resend / Brevo) & Telegram alerts
        Lite\Framework\Providers\ApiProvider::class,      // LiteApi (REST, CORS, JWT)
        Lite\Framework\Providers\AuditProvider::class,    // LiteAudit (Audit Trail)
        Lite\Framework\Providers\AdminProvider::class,    // LiteAdmin (Auto-CRUD Dashboard)
        Lite\Framework\Providers\QueueProvider::class,    // LiteQueue (Background Jobs)
        Lite\Framework\Providers\InertiaProvider::class,  // LiteInertia (React / Vue SPA)
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
    | Multi-Language UI (i18n) Configuration
    |--------------------------------------------------------------------------
    */
    'i18n' => [
        'default'       => (string)($_ENV['APP_LOCALE'] ?? 'vi'),
        'fallback'      => (string)($_ENV['APP_FALLBACK_LOCALE'] ?? 'en'),
        'supported'     => isset($_ENV['APP_SUPPORTED_LOCALES'])
            ? array_values(array_filter(array_map('trim', explode(',', (string)$_ENV['APP_SUPPORTED_LOCALES']))))
            : ['vi', 'en'],
        'detect_header' => $envBool('APP_LOCALE_DETECT_HEADER', true),
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
        'csrf_enabled'    => $envBool('CSRF_ENABLED', true),
        'csrf_secret'     => $_ENV['CSRF_SECRET'] ?? $_ENV['APP_KEY'] ?? 'csrf-secret-key-for-stateless-tokens',
        'admin_ttl'       => (int)($_ENV['ADMIN_SESSION_TTL'] ?? 28800),
        'cookie_secure'   => str_starts_with($appUrl, 'https://'),
        'hsts_enabled'    => $appEnv === 'production' && str_starts_with($appUrl, 'https://'),
        'trusted_proxies' => isset($_ENV['TRUSTED_PROXIES'])
            ? array_values(array_filter(array_map('trim', explode(',', (string)$_ENV['TRUSTED_PROXIES']))))
            : [],
    ],

    'logging' => [
        'retention_days' => (int)($_ENV['LOG_RETENTION_DAYS'] ?? 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactional Mail & Telegram Alerts (Zero External Vendor Dependencies)
    |--------------------------------------------------------------------------
    */
    'mail' => [
        'driver'        => $_ENV['MAIL_DRIVER'] ?? 'log',          // smtp | http | log | array
        'http_provider' => $_ENV['MAIL_HTTP_PROVIDER'] ?? 'resend', // resend | brevo
        'api_key'       => $_ENV['MAIL_API_KEY'] ?? '',
        'host'          => $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com',
        'port'          => (int)($_ENV['MAIL_PORT'] ?? 587),
        'encryption'    => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',       // tls | ssl | none
        'username'      => $_ENV['MAIL_USERNAME'] ?? '',
        'password'      => $_ENV['MAIL_PASSWORD'] ?? '',
        'from_address'  => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@liteplatform.io',
        'from_name'     => $_ENV['MAIL_FROM_NAME'] ?? ($_ENV['APP_NAME'] ?? 'LitePlatform'),
    ],

    'telegram' => [
        'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
        'chat_id'   => $_ENV['TELEGRAM_CHAT_ID'] ?? '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Active Sovereign Feature Modules (Universe Architecture v4.0)
    |--------------------------------------------------------------------------
    | Self-contained domain feature modules with auto-service & route registration.
    */
    'modules' => [
        App\Modules\Inventory\InventoryModule::class,
        App\Modules\RetailExtension\RetailExtensionModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | LiteAdmin Auto-CRUD Dashboard Configuration
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'prefix' => '/admin',
        'entities' => [
            App\Entities\Page::class,
            App\Entities\Post::class,
            App\Entities\Category::class,
            App\Entities\Product::class,
            App\Entities\Redirect::class,
            App\Entities\ContactLead::class,
            App\Entities\User::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API & Security Configuration
    |--------------------------------------------------------------------------
    */
    'api' => [
        'jwt_secret'     => $_ENV['JWT_SECRET'] ?? 'default-jwt-secret-32-chars-long!',
        'protect_writes' => $envBool('API_PROTECT_WRITES', $appEnv === 'production'),
        'cors'           => [
            'allowed_origins' => isset($_ENV['CORS_ALLOWED_ORIGINS'])
                ? array_values(array_filter(array_map('trim', explode(',', (string)$_ENV['CORS_ALLOWED_ORIGINS']))))
                : ($appEnv === 'production' ? [$appUrl] : ['*']),
        ],
    ],
];
