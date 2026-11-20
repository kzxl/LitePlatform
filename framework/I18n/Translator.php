<?php

declare(strict_types=1);

namespace Lite\Framework\I18n;

/**
 * Ultra-lightweight OpCache-backed UI Translator for Shared Hosting.
 * Loads pure PHP array catalogs from lang/{locale}.php with dot-notation keys,
 * named placeholder replacement, pluralization, and automatic fallback.
 */
final class Translator
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogs = [];

    private string $locale;

    /** @var array<string, string> */
    private const OG_LOCALE_MAP = [
        'vi' => 'vi_VN',
        'en' => 'en_US',
        'ja' => 'ja_JP',
        'ko' => 'ko_KR',
        'zh' => 'zh_CN',
        'fr' => 'fr_FR',
        'de' => 'de_DE',
    ];

    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(
        private readonly string $langPath,
        string $defaultLocale = 'vi',
        private readonly string $fallbackLocale = 'en',
        private readonly array $supportedLocales = ['vi', 'en']
    ) {
        $this->locale = $this->isSupported($defaultLocale) ? $defaultLocale : $this->fallbackLocale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * @return list<string>
     */
    public function getSupportedLocales(): array
    {
        return $this->supportedLocales;
    }

    public function isSupported(string $locale): bool
    {
        $normalized = strtolower(trim($locale));
        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $normalized) === 1
            && in_array($normalized, $this->supportedLocales, true);
    }

    public function setLocale(string $locale): self
    {
        $normalized = strtolower(trim($locale));
        if ($this->isSupported($normalized)) {
            $this->locale = $normalized;
        }

        return $this;
    }

    public function getOgLocale(?string $locale = null): string
    {
        $loc = $locale ?? $this->locale;
        return self::OG_LOCALE_MAP[$loc] ?? $loc;
    }

    /**
     * Translate a dot-notation key (e.g. 'home.hero_title') with optional :placeholder replacements.
     *
     * @param array<string, string|int|float> $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $targetLocale = ($locale !== null && $this->isSupported($locale)) ? strtolower($locale) : $this->locale;

        $value = $this->lookup($targetLocale, $key);
        if ($value === null && $targetLocale !== $this->fallbackLocale) {
            $value = $this->lookup($this->fallbackLocale, $key);
        }

        $line = $value ?? $key;
        if ($replace !== []) {
            foreach ($replace as $placeholder => $replacement) {
                $line = str_replace(':' . $placeholder, (string)$replacement, $line);
            }
        }

        return $line;
    }

    /**
     * Pipe-delimited pluralization helper ("0 items|1 item|:count items" or "1 item|:count items").
     *
     * @param array<string, string|int|float> $replace
     */
    public function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        $raw = $this->get($key, [], $locale);
        $parts = explode('|', $raw);

        if (count($parts) === 3) {
            $selected = match (true) {
                $count === 0 => $parts[0],
                $count === 1 => $parts[1],
                default      => $parts[2],
            };
        } elseif (count($parts) === 2) {
            $selected = $count === 1 ? $parts[0] : $parts[1];
        } else {
            $selected = $raw;
        }

        $replace['count'] = $count;
        foreach ($replace as $placeholder => $replacement) {
            $selected = str_replace(':' . $placeholder, (string)$replacement, $selected);
        }

        return trim($selected);
    }

    /**
     * Negotiate the best supported locale from an HTTP Accept-Language header.
     */
    public function negotiateFromHeader(string $acceptLanguage): ?string
    {
        if (trim($acceptLanguage) === '') {
            return null;
        }

        $candidates = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $segments = explode(';', trim($part));
            $tag = strtolower(trim($segments[0]));
            if ($tag === '') {
                continue;
            }
            $q = 1.0;
            if (isset($segments[1]) && preg_match('/q=([0-9.]+)/i', $segments[1], $m)) {
                $q = (float)$m[1];
            }
            $candidates[] = ['tag' => $tag, 'q' => $q];
        }

        usort($candidates, static fn(array $a, array $b): int => $b['q'] <=> $a['q']);

        foreach ($candidates as $candidate) {
            $tag = $candidate['tag'];
            if ($this->isSupported($tag)) {
                return $tag;
            }
            $primary = explode('-', $tag)[0];
            if ($this->isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }

    private function lookup(string $locale, string $key): ?string
    {
        $catalog = $this->loadCatalog($locale);
        if (array_key_exists($key, $catalog) && is_string($catalog[$key])) {
            return $catalog[$key];
        }

        $curr = $catalog;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($curr) || !array_key_exists($segment, $curr)) {
                return null;
            }
            $curr = $curr[$segment];
        }

        return is_string($curr) ? $curr : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCatalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }

        if (!$this->isSupported($locale)) {
            return $this->catalogs[$locale] = [];
        }

        $file = rtrim($this->langPath, '/\\') . DIRECTORY_SEPARATOR . $locale . '.php';
        if (!is_file($file)) {
            return $this->catalogs[$locale] = [];
        }

        $data = require $file;
        return $this->catalogs[$locale] = is_array($data) ? $data : [];
    }
}
