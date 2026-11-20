<?php

declare(strict_types=1);

namespace LiteUtil\Number;

use LiteUtil\Number\Formatter\{VietnameseFormatter, EnglishFormatter};

/**
 * Universal Multilingual Number to Words Façade & Formatter Registry.
 */
class NumberToWords
{
    /** @var array<string, LanguageFormatterInterface> */
    private static array $formatters = [];

    /**
     * Convert number to spoken words in the specified language.
     *
     * @param int|float|string $number
     * @param string $locale Language code: 'vi', 'en', etc.
     */
    public static function toWords(int|float|string $number, string $locale = 'vi'): string
    {
        return self::getFormatter($locale)->formatNumber($number);
    }

    /**
     * Convert monetary amount to words in the specified language and currency.
     *
     * @param int|float|string $amount
     * @param string $currency ISO-4217 currency code (e.g. 'VND', 'USD', 'EUR')
     * @param string $locale Language code: 'vi', 'en', etc.
     * @param bool $subUnits Whether to format sub-units (cents, xu)
     */
    public static function toCurrency(
        int|float|string $amount,
        string $currency = 'VND',
        string $locale = 'vi',
        bool $subUnits = true,
    ): string {
        return self::getFormatter($locale)->formatCurrency($amount, $currency, $subUnits);
    }

    /**
     * Get or lazy-instantiate the formatter for a given locale.
     *
     * @throws \InvalidArgumentException
     */
    public static function getFormatter(string $locale): LanguageFormatterInterface
    {
        $normalized = strtolower(substr($locale, 0, 2));

        if (!isset(self::$formatters[$normalized])) {
            self::$formatters[$normalized] = match ($normalized) {
                'vi' => new VietnameseFormatter(),
                'en' => new EnglishFormatter(),
                default => throw new \InvalidArgumentException("No formatter registered for locale '{$locale}'"),
            };
        }

        return self::$formatters[$normalized];
    }

    /**
     * Register or override a custom language formatter.
     */
    public static function registerFormatter(string $locale, LanguageFormatterInterface $formatter): void
    {
        $normalized = strtolower(substr($locale, 0, 2));
        self::$formatters[$normalized] = $formatter;
    }

    /**
     * Reset registered formatters back to default.
     */
    public static function reset(): void
    {
        self::$formatters = [];
    }
}
