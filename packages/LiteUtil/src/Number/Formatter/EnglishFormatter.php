<?php

declare(strict_types=1);

namespace LiteUtil\Number\Formatter;

use LiteUtil\Number\LanguageFormatterInterface;

/**
 * Standard English Number and Currency to Words formatter.
 * Handles numbers up to quadrillions, negative numbers, decimals, and standard currencies (USD, EUR, GBP, VND).
 */
class EnglishFormatter implements LanguageFormatterInterface
{
    private const ONES = [
        0 => 'zero',
        1 => 'one',
        2 => 'two',
        3 => 'three',
        4 => 'four',
        5 => 'five',
        6 => 'six',
        7 => 'seven',
        8 => 'eight',
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
        14 => 'fourteen',
        15 => 'fifteen',
        16 => 'sixteen',
        17 => 'seventeen',
        18 => 'eighteen',
        19 => 'nineteen',
    ];

    private const TENS = [
        2 => 'twenty',
        3 => 'thirty',
        4 => 'forty',
        5 => 'fifty',
        6 => 'sixty',
        7 => 'seventy',
        8 => 'eighty',
        9 => 'ninety',
    ];

    private const SCALES = [
        '',
        'thousand',
        'million',
        'billion',
        'trillion',
        'quadrillion',
    ];

    public function __construct(
        private readonly bool $useAnd = false, // e.g. "one hundred and twenty" vs "one hundred twenty"
        private readonly bool $capitalizeFirst = false,
    ) {}

    public function formatNumber(int|float|string $number): string
    {
        $numStr = trim((string) $number);
        if ($numStr === '' || $numStr === '0') {
            return $this->finalize('zero');
        }

        $isNegative = str_starts_with($numStr, '-');
        if ($isNegative) {
            $numStr = substr($numStr, 1);
        }

        $parts = explode('.', $numStr, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') $intPart = '0';
        $decPart = $parts[1] ?? null;

        if ($intPart === '0' && ($decPart === null || ltrim($decPart, '0') === '')) {
            return $this->finalize('zero');
        }

        $words = [];
        if ($intPart === '0') {
            $words[] = 'zero';
        } else {
            $words[] = $this->formatIntegerPart($intPart);
        }

        if ($decPart !== null && rtrim($decPart, '0') !== '') {
            $decPart = rtrim($decPart, '0');
            $decWords = [];
            for ($i = 0; $i < strlen($decPart); $i++) {
                $d = (int) $decPart[$i];
                $decWords[] = self::ONES[$d] ?? '';
            }
            $words[] = 'point ' . implode(' ', $decWords);
        }

        $result = implode(' ', $words);
        if ($isNegative) {
            $result = 'minus ' . $result;
        }

        return $this->finalize($result);
    }

    public function formatCurrency(int|float|string $amount, string $currency = 'USD', bool $subUnits = true): string
    {
        $numStr = trim((string) $amount);
        $isNegative = str_starts_with($numStr, '-');
        if ($isNegative) {
            $numStr = substr($numStr, 1);
        }

        $parts = explode('.', $numStr, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') $intPart = '0';
        $decPart = isset($parts[1]) ? substr(rtrim($parts[1], '0'), 0, 2) : '';

        $intVal = (int) $intPart;
        $intWords = $intPart === '0' ? 'zero' : $this->formatIntegerPart($intPart);

        [$mainUnitSingular, $mainUnitPlural, $subUnitSingular, $subUnitPlural] = match (strtoupper($currency)) {
            'USD' => ['dollar', 'dollars', 'cent', 'cents'],
            'EUR' => ['euro', 'euros', 'cent', 'cents'],
            'GBP' => ['pound', 'pounds', 'penny', 'pence'],
            'VND' => ['dong', 'dong', 'xu', 'xu'],
            default => [strtolower($currency), strtolower($currency) . 's', 'cent', 'cents'],
        };

        $mainUnit = ($intVal === 1) ? $mainUnitSingular : $mainUnitPlural;
        $result = $intWords . ' ' . $mainUnit;

        if ($subUnits && $decPart !== '') {
            $cents = (int) str_pad($decPart, 2, '0');
            if ($cents > 0) {
                $subWords = $this->formatIntegerPart((string) $cents);
                $subUnit = ($cents === 1) ? $subUnitSingular : $subUnitPlural;
                $result .= ' and ' . $subWords . ' ' . $subUnit;
            }
        }

        if ($isNegative) {
            $result = 'minus ' . $result;
        }

        return $this->finalize($result);
    }

    private function formatIntegerPart(string $intStr): string
    {
        $groups = [];
        $len = strlen($intStr);
        for ($i = $len; $i > 0; $i -= 3) {
            $start = max(0, $i - 3);
            $chunk = substr($intStr, $start, $i - $start);
            $groups[] = (int) $chunk;
        }

        $resultGroups = [];
        $groupCount = count($groups);

        for ($idx = 0; $idx < $groupCount; $idx++) {
            $val = $groups[$idx];
            if ($val === 0) continue;

            $groupWords = $this->formatThreeDigits($val);
            $scale = self::SCALES[$idx] ?? '';

            if ($scale !== '') {
                $resultGroups[] = $groupWords . ' ' . $scale;
            } else {
                $resultGroups[] = $groupWords;
            }
        }

        if (empty($resultGroups)) {
            return 'zero';
        }

        return implode(' ', array_reverse($resultGroups));
    }

    private function formatThreeDigits(int $num): string
    {
        $h = intdiv($num, 100);
        $rem = $num % 100;

        $words = [];
        if ($h > 0) {
            $words[] = self::ONES[$h] . ' hundred';
            if ($rem > 0 && $this->useAnd) {
                $words[] = 'and';
            }
        }

        if ($rem > 0) {
            if ($rem < 20) {
                $words[] = self::ONES[$rem];
            } else {
                $t = intdiv($rem, 10);
                $u = $rem % 10;
                $tensStr = self::TENS[$t];
                if ($u > 0) {
                    $tensStr .= '-' . self::ONES[$u];
                }
                $words[] = $tensStr;
            }
        }

        return implode(' ', $words);
    }

    private function finalize(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($this->capitalizeFirst && $text !== '') {
            return ucfirst($text);
        }
        return $text;
    }
}
