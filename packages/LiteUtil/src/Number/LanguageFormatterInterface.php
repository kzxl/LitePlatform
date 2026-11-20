<?php

declare(strict_types=1);

namespace LiteUtil\Number;

interface LanguageFormatterInterface
{
    /**
     * Convert a numeric value into spoken words.
     */
    public function formatNumber(int|float|string $number): string;

    /**
     * Convert a monetary value into formal currency words.
     *
     * @param int|float|string $amount
     * @param string $currency ISO-4217 currency code (e.g. 'VND', 'USD', 'EUR')
     * @param bool $subUnits Whether to format cents/hào/xu
     */
    public function formatCurrency(int|float|string $amount, string $currency = 'VND', bool $subUnits = true): string;
}
