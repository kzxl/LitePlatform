<?php

declare(strict_types=1);

namespace LiteUtil\Number\Formatter;

use LiteUtil\Number\LanguageFormatterInterface;

/**
 * Standard Vietnamese Number and Currency to Words formatter.
 * Handles complex grammar rules: "mười một", "hai mươi mốt", "mười lăm", "lẻ/linh", scale groups up to quadrillions (tỷ tỷ).
 */
class VietnameseFormatter implements LanguageFormatterInterface
{
    private const DIGITS = [
        0 => 'không',
        1 => 'một',
        2 => 'hai',
        3 => 'ba',
        4 => 'bốn',
        5 => 'năm',
        6 => 'sáu',
        7 => 'bảy',
        8 => 'tám',
        9 => 'chín',
    ];

    private const SCALES = [
        '',
        'nghìn',
        'triệu',
        'tỷ',
        'nghìn tỷ',
        'triệu tỷ',
        'tỷ tỷ',
    ];

    public function __construct(
        private readonly string $zeroTenPrefix = 'lẻ', // 'lẻ' or 'linh'
        private readonly bool $capitalizeFirst = false,
    ) {}

    public function formatNumber(int|float|string $number): string
    {
        $numStr = (string) $number;
        $numStr = trim($numStr);

        if ($numStr === '' || $numStr === '0') {
            return $this->finalize('không');
        }

        $isNegative = str_starts_with($numStr, '-');
        if ($isNegative) {
            $numStr = substr($numStr, 1);
        }

        // Split integer and decimal parts
        $parts = explode('.', $numStr, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $decPart = $parts[1] ?? null;

        if ($intPart === '0' && ($decPart === null || ltrim($decPart, '0') === '')) {
            return $this->finalize('không');
        }

        $words = [];
        if ($intPart === '0') {
            $words[] = 'không';
        } else {
            $words[] = $this->formatIntegerPart($intPart);
        }

        if ($decPart !== null && rtrim($decPart, '0') !== '') {
            $decPart = rtrim($decPart, '0');
            $decWords = [];
            for ($i = 0; $i < strlen($decPart); $i++) {
                $d = (int) $decPart[$i];
                $decWords[] = self::DIGITS[$d] ?? '';
            }
            $words[] = 'phẩy ' . implode(' ', $decWords);
        }

        $result = implode(' ', $words);
        if ($isNegative) {
            $result = 'âm ' . $result;
        }

        return $this->finalize($result);
    }

    public function formatCurrency(int|float|string $amount, string $currency = 'VND', bool $subUnits = true): string
    {
        $numStr = (string) $amount;
        $isNegative = str_starts_with($numStr, '-');
        if ($isNegative) {
            $numStr = substr($numStr, 1);
        }

        $parts = explode('.', $numStr, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') $intPart = '0';
        $decPart = isset($parts[1]) ? substr(rtrim($parts[1], '0'), 0, 2) : '';

        $intWords = $intPart === '0' ? 'không' : $this->formatIntegerPart($intPart);

        $currencyUnit = match (strtoupper($currency)) {
            'VND' => 'đồng',
            'USD' => 'đô la Mỹ',
            'EUR' => 'euro',
            'JPY' => 'yên Nhật',
            default => strtolower($currency),
        };

        $result = $intWords . ' ' . $currencyUnit;

        if ($subUnits && $decPart !== '') {
            $cents = (int) str_pad($decPart, 2, '0');
            if ($cents > 0) {
                $subUnitName = strtoupper($currency) === 'VND' ? 'xu' : 'cent';
                $centsWords = $this->formatIntegerPart((string) $cents);
                $result .= ' và ' . $centsWords . ' ' . $subUnitName;
            }
        } elseif (strtoupper($currency) === 'VND' && ($decPart === '' || $decPart === '0' || $decPart === '00')) {
            $result .= ' chẵn';
        }

        if ($isNegative) {
            $result = 'âm ' . $result;
        }

        return $this->finalize($result);
    }

    private function formatIntegerPart(string $intStr): string
    {
        // Split into groups of 3 digits from right to left
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
            if ($val === 0) {
                // If value is 0, check if this scale is billions (tỷ)
                if ($idx % 3 === 0 && $idx > 0) {
                    // Repeat tỷ if needed
                }
                continue;
            }

            $isHighest = ($idx === $groupCount - 1);
            $groupWords = $this->formatThreeDigits($val, !$isHighest);
            $scale = self::SCALES[$idx] ?? 'tỷ';

            if ($scale !== '') {
                $resultGroups[] = $groupWords . ' ' . $scale;
            } else {
                $resultGroups[] = $groupWords;
            }
        }

        if (empty($resultGroups)) {
            return 'không';
        }

        return implode(' ', array_reverse($resultGroups));
    }

    private function formatThreeDigits(int $num, bool $hasHigher): string
    {
        $h = intdiv($num, 100);
        $rem = $num % 100;
        $t = intdiv($rem, 10);
        $u = $rem % 10;

        $words = [];

        // Hundreds
        if ($h > 0) {
            $words[] = self::DIGITS[$h] . ' trăm';
        } elseif ($hasHigher && ($t > 0 || $u > 0)) {
            $words[] = 'không trăm';
        }

        // Tens
        if ($t === 0) {
            if ($u > 0 && ($h > 0 || $hasHigher)) {
                $words[] = $this->zeroTenPrefix;
            }
        } elseif ($t === 1) {
            $words[] = 'mười';
        } else {
            $words[] = self::DIGITS[$t] . ' mươi';
        }

        // Ones
        if ($u > 0) {
            if ($u === 1) {
                if ($t >= 2) {
                    $words[] = 'mốt';
                } else {
                    $words[] = 'một';
                }
            } elseif ($u === 5) {
                if ($t >= 1) {
                    $words[] = 'lăm';
                } else {
                    $words[] = 'năm';
                }
            } else {
                $words[] = self::DIGITS[$u];
            }
        }

        return implode(' ', $words);
    }

    private function finalize(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($this->capitalizeFirst && $text !== '') {
            $firstChar = mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8');
            $rest = mb_substr($text, 1, null, 'UTF-8');
            return $firstChar . $rest;
        }
        return $text;
    }
}
