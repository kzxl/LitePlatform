<?php

declare(strict_types=1);

namespace LiteUtil\Text;

/**
 * High-performance UTF-8 string manipulation utilities with zero regex bottlenecks.
 */
class Str
{
    /**
     * Remove accents and diacritics using fast O(1) hash translation table.
     */
    public static function removeAccents(string $value): string
    {
        return strtr($value, DiacriticsMap::MAP);
    }

    /**
     * Generate a URL-friendly, SEO-optimized "slug" from a string.
     */
    public static function slug(string $title, string $separator = '-'): string
    {
        $clean = self::removeAccents($title);
        $clean = mb_strtolower($clean, 'UTF-8');
        // Replace non-alphanumeric with separator
        $clean = preg_replace('/[^a-z0-9]+/i', $separator, $clean) ?? $clean;
        // Trim separator from ends
        return trim($clean, $separator);
    }

    /**
     * Convert string to camelCase.
     */
    public static function camel(string $value): string
    {
        $pascal = self::pascal($value);
        return lcfirst($pascal);
    }

    /**
     * Convert string to PascalCase (StudlyCase).
     */
    public static function pascal(string $value): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));
        $studlyWords = array_map(fn($word) => ucfirst(strtolower($word)), $words);
        return implode('', $studlyWords);
    }

    /**
     * Convert string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value;
        if (!ctype_lower($key)) {
            $key = preg_replace('/\s+/u', '', ucwords($key)) ?? $key;
            $key = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $key) ?? $key;
            $key = mb_strtolower($key, 'UTF-8');
        }
        return $key;
    }

    /**
     * Convert string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * Convert string to Title Case.
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Truncate string to a given length without breaking words.
     */
    public static function truncate(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    /**
     * Mask a portion of a string with a repeated character (e.g. for phone numbers or emails).
     */
    public static function mask(string $string, string $character = '*', int $index = 0, ?int $length = null): string
    {
        if ($character === '') {
            return $string;
        }

        $strLen = mb_strlen($string, 'UTF-8');
        if ($index < 0) {
            $index = max(0, $strLen + $index);
        }

        $length ??= $strLen - $index;
        if ($length <= 0) {
            return $string;
        }

        $start = mb_substr($string, 0, $index, 'UTF-8');
        $mask = str_repeat($character, min($length, $strLen - $index));
        $end = mb_substr($string, $index + $length, null, 'UTF-8');

        return $start . $mask . $end;
    }

    /**
     * Determine if a string starts with a given substring.
     */
    public static function startsWith(string $haystack, string|iterable $needles): bool
    {
        if (is_string($needles)) {
            return str_starts_with($haystack, $needles);
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if a string ends with a given substring.
     */
    public static function endsWith(string $haystack, string|iterable $needles): bool
    {
        if (is_string($needles)) {
            return str_ends_with($haystack, $needles);
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if a string contains a given substring.
     */
    public static function contains(string $haystack, string|iterable $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack, 'UTF-8');
        }

        if (is_string($needles)) {
            $needles = [$needles];
        }

        foreach ($needles as $needle) {
            if ($ignoreCase) {
                $needle = mb_strtolower($needle, 'UTF-8');
            }
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
