<?php

declare(strict_types=1);

namespace Lite\Framework\Cms;

use LiteCache\CacheInterface;
use PDO;

/**
 * Zero-query-per-request Site Settings store backed by PDO + LiteCache (FileOpCache).
 * Ideal for dynamic Logo, Hotline, Footer, GA4, Meta Pixel, and Search Console verification.
 */
final class SiteSettings
{
    private const CACHE_KEY = 'cms_site_settings_all';
    private const CACHE_TAG = 'site_settings';

    /** @var array<string, string>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 3600
    ) {
    }

    /**
     * Load all settings (in-process memoized -> LiteCache -> PDO).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $loader = function (): array {
            try {
                $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM site_settings');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $map = [];
                foreach ($rows as $row) {
                    $map[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
                }
                return $map;
            } catch (\Throwable) {
                return [];
            }
        };

        if ($this->cache !== null) {
            $data = $this->cache->tags([self::CACHE_TAG])->remember(self::CACHE_KEY, $this->cacheTtl, $loader);
            return $this->memo = is_array($data) ? $data : [];
        }

        return $this->memo = $loader();
    }

    public function get(string $key, string $default = ''): string
    {
        $all = $this->all();
        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    }

    public function set(string $key, string $value, string $group = 'general'): void
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = date('Y-m-d H:i:s');

        if ($driver === 'mysql') {
            $sql = 'INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                    VALUES (:k, :v, :g, :u)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_group = VALUES(setting_group), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
                    VALUES (:k, :v, :g, :u)
                    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, setting_group = excluded.setting_group, updated_at = excluded.updated_at';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':k' => $key, ':v' => $value, ':g' => $group, ':u' => $now]);

        $this->invalidate();
    }

    /**
     * @param array<string, string> $pairs
     */
    public function setMany(array $pairs, string $group = 'general'): void
    {
        foreach ($pairs as $key => $value) {
            $this->set($key, $value, $group);
        }
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM site_settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        $this->invalidate();
    }

    public function invalidate(): void
    {
        $this->memo = null;
        if ($this->cache !== null) {
            $this->cache->tags([self::CACHE_TAG])->flushTags();
        }
    }

    /**
     * Render strictly validated SEO verification & analytics head tags
     * (blocks arbitrary XSS injection via settings table).
     */
    public function renderTrackingHead(): string
    {
        $out = [];
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $gsc = trim($this->get('search_console_token'));
        if ($gsc !== '' && preg_match('/^[a-zA-Z0-9_\-]{10,128}$/', $gsc)) {
            $out[] = '<meta name="google-site-verification" content="' . $e($gsc) . '">';
        }

        $ga4 = strtoupper(trim($this->get('ga4_id')));
        if ($ga4 !== '' && preg_match('/^G-[A-Z0-9]{6,20}$/', $ga4)) {
            $out[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $e($ga4) . '"></script>';
            $out[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$ga4}');</script>";
        }

        $pixel = trim($this->get('meta_pixel_id'));
        if ($pixel !== '' && ctype_digit($pixel) && strlen($pixel) >= 8 && strlen($pixel) <= 24) {
            $out[] = "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$pixel}');fbq('track','PageView');</script>";
        }

        return implode("\n    ", $out);
    }
}
