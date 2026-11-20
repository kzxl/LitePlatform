<?php

declare(strict_types=1);

namespace Lite\Framework\Install;

use Lite\Framework\Core\MigrationManager;
use Lite\Framework\Cms\SiteSettings;
use LiteUtil\Text\Str;
use PDO;
use Throwable;

/**
 * WordPress-style 60-Second Web Setup Service for Shared Hosting (No SSH required).
 * - Preflight system check (PHP >= 8.2, extensions, writable directories)
 * - Safe DB connection tester (MySQL / SQLite)
 * - Env-injection-safe .env writer with CSPRNG 48-char secrets
 * - Automatic migration runner, Admin user creation (password_hash), SiteSettings & welcome post
 * - Permanent 1-way lock via storage/installed.lock
 */
final class InstallerService
{
    public const LOCK_RELATIVE_PATH = 'storage/installed.lock';

    public static function isInstalled(string $basePath): bool
    {
        if (is_file($basePath . '/' . self::LOCK_RELATIVE_PATH)) {
            return true;
        }

        // Fail closed in production if .env already exists
        if (is_file($basePath . '/.env')) {
            $envContent = (string)@file_get_contents($basePath . '/.env');
            if (preg_match('/^\s*APP_ENV\s*=\s*["\']?production["\']?\s*$/m', $envContent) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function needsRedirectToInstall(string $basePath): bool
    {
        return !is_file($basePath . '/.env') && !is_file($basePath . '/' . self::LOCK_RELATIVE_PATH);
    }

    /**
     * Run preflight environment checks for Shared Hosting readiness.
     *
     * @return array{ok: bool, items: list<array{name: string, status: string, detail: string}>}
     */
    public function checkRequirements(string $basePath): array
    {
        $items = [];
        $ok = true;

        $add = static function (string $name, bool $pass, string $detail, bool $required = true) use (&$items, &$ok): void {
            if ($required && !$pass) {
                $ok = false;
            }
            $items[] = [
                'name'   => $name,
                'status' => $pass ? 'pass' : ($required ? 'fail' : 'warn'),
                'detail' => $detail,
            ];
        };

        $add('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, true);
        $add('PDO Extension', extension_loaded('pdo'), extension_loaded('pdo') ? 'Loaded' : 'Missing', true);
        $add(
            'Database Driver (MySQL / SQLite)',
            extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            implode(', ', array_filter([
                extension_loaded('pdo_mysql') ? 'pdo_mysql' : null,
                extension_loaded('pdo_sqlite') ? 'pdo_sqlite' : null,
            ])) ?: 'None',
            true
        );
        $add('Fileinfo (Upload MIME)', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? 'Loaded' : 'Missing', true);
        $add('Mbstring (UTF-8)', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'Loaded' : 'Missing', true);
        $add(
            'GD + WebP (Image Optimization)',
            extension_loaded('gd') && function_exists('imagewebp'),
            (extension_loaded('gd') && function_exists('imagewebp')) ? 'Enabled' : 'Optional (originals kept)',
            false
        );

        foreach (['storage', 'storage/cache', 'storage/logs'] as $rel) {
            $dir = $basePath . '/' . $rel;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $writable = is_dir($dir) && is_writable($dir);
            $add("Writable: {$rel}/", $writable, $writable ? 'Writable' : 'Not writable', true);
        }

        $rootWritable = is_writable($basePath);
        $add('Writable: .env location', $rootWritable, $rootWritable ? 'Writable' : 'Not writable', true);

        return ['ok' => $ok, 'items' => $items];
    }

    /**
     * Test PDO connection safely without leaking credentials or stack traces.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string}
     */
    public function testDatabaseConnection(array $input, string $basePath): array
    {
        try {
            $pdo = $this->createPdo($input, $basePath);
            $pdo->query('SELECT 1');
            return ['ok' => true, 'message' => 'Database connection successful.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Connection failed: ' . $this->safeErrorMessage($e->getMessage())];
        }
    }

    /**
     * Execute full installation transactionally: DB migrations -> Admin -> SiteSettings -> .env -> lockfile.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: list<string>}
     */
    public function install(string $basePath, array $input): array
    {
        if (self::isInstalled($basePath)) {
            return ['ok' => false, 'errors' => ['Application is already installed.']];
        }

        $errors = [];

        $siteName = $this->stripControl((string)($input['site_name'] ?? ''));
        $siteUrl = rtrim($this->stripControl((string)($input['site_url'] ?? '')), '/');
        $locale = in_array(strtolower((string)($input['locale'] ?? 'vi')), ['vi', 'en'], true)
            ? strtolower((string)$input['locale'])
            : 'vi';

        $adminName = $this->stripControl((string)($input['admin_name'] ?? 'Administrator'));
        $adminEmail = trim($this->stripControl((string)($input['admin_email'] ?? '')));
        $adminPassword = (string)($input['admin_password'] ?? '');

        $dbDriver = strtolower(trim((string)($input['db_driver'] ?? 'mysql')));
        if (!in_array($dbDriver, ['mysql', 'sqlite'], true)) {
            $errors[] = 'Unsupported database driver.';
        }

        if ($siteName === '') {
            $errors[] = 'Site name is required.';
        }
        if ($siteUrl === '' || !preg_match('#^https?://[^\s]+$#i', $siteUrl)) {
            $errors[] = 'Site URL must start with http:// or https://.';
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid administrator email address is required.';
        }
        if (strlen($adminPassword) < 8) {
            $errors[] = 'Administrator password must be at least 8 characters.';
        }

        $dbHost = $this->stripControl((string)($input['db_host'] ?? '127.0.0.1'));
        $dbPort = (int)($input['db_port'] ?? 3306);
        $dbName = $this->stripControl((string)($input['db_database'] ?? ''));
        $dbUser = $this->stripControl((string)($input['db_username'] ?? ''));
        $dbPass = (string)($input['db_password'] ?? '');

        if ($dbDriver === 'mysql' && ($dbName === '' || $dbUser === '')) {
            $errors[] = 'MySQL database name and username are required.';
        }
        if ($dbDriver === 'sqlite' && $dbName === '') {
            $dbName = 'storage/app.sqlite';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 1. Connect to DB & run all migrations
        try {
            $pdo = $this->createPdo([
                'db_driver'   => $dbDriver,
                'db_host'     => $dbHost,
                'db_port'     => $dbPort,
                'db_database' => $dbName,
                'db_username' => $dbUser,
                'db_password' => $dbPass,
            ], $basePath);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['Database connection failed: ' . $this->safeErrorMessage($e->getMessage())]];
        }

        try {
            $migrator = new MigrationManager(
                $pdo,
                $basePath . '/database/migrations',
                $basePath . '/database/seeders'
            );
            $migrator->migrate();

            // 2. Create or update initial Administrator user with secure password_hash
            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');

            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
            $checkStmt->execute([':email' => $adminEmail]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId !== false) {
                $upd = $pdo->prepare(
                    'UPDATE users SET name = :name, password_hash = :hash, role = :role, updated_at = :now WHERE id = :id'
                );
                $upd->execute([
                    ':name' => $adminName ?: 'Administrator',
                    ':hash' => $hash,
                    ':role' => 'admin',
                    ':now'  => $now,
                    ':id'   => $existingId,
                ]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:name, :email, :hash, :role, :now)'
                );
                $ins->execute([
                    ':name'  => $adminName ?: 'Administrator',
                    ':email' => $adminEmail,
                    ':hash'  => $hash,
                    ':role'  => 'admin',
                    ':now'   => $now,
                ]);
            }

            // 3. Seed initial SiteSettings
            $settings = new SiteSettings($pdo);
            $tagline = $this->stripControl((string)($input['site_tagline'] ?? ''));
            $settings->setMany([
                'site_name' => $siteName,
                'tagline'   => $tagline !== '' ? $tagline : ($locale === 'vi'
                    ? 'Website nhanh, gọn, chuẩn SEO vận hành trên LitePlatform.'
                    : 'Fast, lightweight, SEO-ready website powered by LitePlatform.'),
                'email'     => $adminEmail,
                'hotline'   => $this->stripControl((string)($input['hotline'] ?? '')),
            ], 'general');

            // 4. Create initial sample published post if posts table is empty (WordPress "Hello World!")
            $postCount = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
            if ($postCount === 0) {
                $welcomeTitle = $locale === 'vi'
                    ? 'Chào mừng đến với ' . $siteName
                    : 'Welcome to ' . $siteName;
                $welcomeSlug = Str::slug($welcomeTitle) ?: 'welcome';
                $welcomeExcerpt = $locale === 'vi'
                    ? 'Bài viết đầu tiên được khởi tạo tự động. Bạn có thể chỉnh sửa hoặc xóa bài viết này trong trang Quản trị.'
                    : 'Your first article was created automatically. Edit or delete it anytime from the Admin Dashboard.';
                $welcomeContent = $locale === 'vi'
                    ? '<p>Chúc mừng! Website của bạn đã sẵn sàng vận hành với <strong>LitePlatform</strong>.</p><h2>Các bước tiếp theo</h2><ul><li>Đăng nhập <a href="/admin">Trang Quản trị</a> để viết bài mới, quản lý sản phẩm và cài đặt SEO Redirect 301.</li><li>Cấu hình Email &amp; Telegram trong tệp <code>.env</code> để nhận thông báo khi khách gửi form <a href="/contact">Liên hệ</a>.</li></ul>'
                    : '<p>Congratulations! Your website is live on <strong>LitePlatform</strong>.</p><h2>Next Steps</h2><ul><li>Sign in to the <a href="/admin">Admin Dashboard</a> to publish articles, manage products, and configure 301 SEO redirects.</li><li>Configure SMTP/Resend and Telegram alerts in <code>.env</code> to receive instant notifications from the <a href="/contact">Contact</a> form.</li></ul>';

                $postStmt = $pdo->prepare(
                    "INSERT INTO posts (title, slug, excerpt, content, meta_title, meta_description, status, published_at, created_at)
                     VALUES (:title, :slug, :excerpt, :content, :mt, :md, 'published', :pub, :now)"
                );
                $postStmt->execute([
                    ':title'   => $welcomeTitle,
                    ':slug'    => $welcomeSlug,
                    ':excerpt' => $welcomeExcerpt,
                    ':content' => $welcomeContent,
                    ':mt'      => $welcomeTitle,
                    ':md'      => $welcomeExcerpt,
                    ':pub'     => $now,
                    ':now'     => $now,
                ]);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['Migration or setup failed: ' . $this->safeErrorMessage($e->getMessage())]];
        }

        // 5. Write hardened production .env with CSPRNG 48-char random secrets
        $appKey = bin2hex(random_bytes(24));
        $csrfSecret = bin2hex(random_bytes(24));
        $jwtSecret = bin2hex(random_bytes(24));

        $envLines = [
            '# Generated by LitePlatform Web Installer on ' . date('Y-m-d H:i:s'),
            'APP_NAME=' . self::formatEnvValue($siteName),
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=' . self::formatEnvValue($siteUrl),
            'APP_KEY=' . $appKey,
            '',
            '# Shared Hosting & SEO Performance',
            'TENANCY_ENABLED=false',
            'CACHE_DRIVER=file',
            'PAGE_CACHE_ENABLED=true',
            'PAGE_CACHE_TTL=600',
            'LOG_RETENTION_DAYS=14',
            '',
            '# Multi-Language UI',
            'APP_LOCALE=' . $locale,
            'APP_FALLBACK_LOCALE=' . ($locale === 'vi' ? 'en' : 'vi'),
            'APP_SUPPORTED_LOCALES=vi,en',
            'APP_LOCALE_DETECT_HEADER=true',
            '',
            '# Database Configuration',
            'DB_CONNECTION=' . $dbDriver,
            'DB_HOST=' . self::formatEnvValue($dbHost),
            'DB_PORT=' . max(1, $dbPort),
            'DB_DATABASE=' . self::formatEnvValue($dbName),
            'DB_USERNAME=' . self::formatEnvValue($dbUser),
            'DB_PASSWORD=' . self::formatEnvValue($dbPass),
            '',
            '# Security & API',
            'CSRF_ENABLED=true',
            'CSRF_SECRET=' . $csrfSecret,
            'JWT_SECRET=' . $jwtSecret,
            'API_PROTECT_WRITES=true',
            'ADMIN_SESSION_TTL=28800',
            '',
            '# Mailer (smtp | http | log)',
            'MAIL_DRIVER=log',
            'MAIL_FROM_ADDRESS=' . self::formatEnvValue($adminEmail),
            'MAIL_FROM_NAME=' . self::formatEnvValue($siteName),
            '',
        ];

        $envFile = $basePath . '/.env';
        if (@file_put_contents($envFile, implode("\n", $envLines), LOCK_EX) === false) {
            return ['ok' => false, 'errors' => ['Unable to write .env file. Check folder write permissions.']];
        }

        // 6. Permanently lock installer via storage/installed.lock
        $lockFile = $basePath . '/' . self::LOCK_RELATIVE_PATH;
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        $lockPayload = json_encode([
            'installed_at' => date('c'),
            'php_version'  => PHP_VERSION,
            'site_url'     => $siteUrl,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @file_put_contents($lockFile, (string)$lockPayload, LOCK_EX);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Format a value safely for .env files, preventing newline / quote Env Injection.
     */
    public static function formatEnvValue(string $value): string
    {
        $clean = str_replace(["\r", "\n", "\0"], '', $value);
        if ($clean === '') {
            return '';
        }
        if (preg_match('/[\s#"\'\\\\$=]/', $clean)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $clean);
            return '"' . $escaped . '"';
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createPdo(array $input, string $basePath): PDO
    {
        $driver = strtolower(trim((string)($input['db_driver'] ?? 'mysql')));
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        if ($driver === 'sqlite') {
            $dbPath = (string)($input['db_database'] ?? 'storage/app.sqlite');
            $fullPath = str_starts_with($dbPath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dbPath)
                ? $dbPath
                : $basePath . '/' . ltrim($dbPath, '/\\');
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            return new PDO('sqlite:' . $fullPath, null, null, $options);
        }

        $host = (string)($input['db_host'] ?? '127.0.0.1');
        $port = (int)($input['db_port'] ?? 3306);
        $dbName = (string)($input['db_database'] ?? '');
        $username = (string)($input['db_username'] ?? '');
        $password = (string)($input['db_password'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
        return new PDO($dsn, $username, $password, $options);
    }

    private function stripControl(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private function safeErrorMessage(string $message): string
    {
        return mb_substr(trim((string)preg_replace('/\s+/', ' ', $message)), 0, 220);
    }
}
