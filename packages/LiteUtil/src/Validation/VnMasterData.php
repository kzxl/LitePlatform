<?php

declare(strict_types=1);

namespace LiteUtil\Validation;

/**
 * Enterprise validation and normalization engine for Vietnamese Master Data.
 * Complies with Circular 105/2020/TT-BTC (Tax ID / MST) and Ministry of Public Security C06 standards (CCCD).
 */
class VnMasterData
{
    private const MST_WEIGHTS = [31, 29, 23, 19, 17, 13, 7, 5, 3];

    private const PROVINCES = [
        1 => 'Hà Nội', 2 => 'Hà Giang', 4 => 'Cao Bằng', 6 => 'Bắc Kạn',
        8 => 'Tuyên Quang', 10 => 'Lào Cai', 11 => 'Điện Biên', 12 => 'Lai Châu',
        14 => 'Sơn La', 15 => 'Yên Bái', 17 => 'Hòa Bình', 19 => 'Thái Nguyên',
        20 => 'Lạng Sơn', 22 => 'Quảng Ninh', 24 => 'Bắc Giang', 25 => 'Phú Thọ',
        26 => 'Vĩnh Phúc', 27 => 'Bắc Ninh', 30 => 'Hải Dương', 31 => 'Hải Phòng',
        33 => 'Hưng Yên', 34 => 'Thái Bình', 35 => 'Hà Nam', 36 => 'Nam Định',
        37 => 'Ninh Bình', 38 => 'Thanh Hóa', 40 => 'Nghệ An', 42 => 'Hà Tĩnh',
        44 => 'Quảng Bình', 45 => 'Quảng Trị', 46 => 'Thừa Thiên Huế', 48 => 'Đà Nẵng',
        49 => 'Quảng Nam', 51 => 'Quảng Ngãi', 52 => 'Bình Định', 54 => 'Phú Yên',
        56 => 'Khánh Hòa', 58 => 'Ninh Thuận', 60 => 'Bình Thuận', 62 => 'Kon Tum',
        64 => 'Gia Lai', 66 => 'Đắk Lắk', 67 => 'Đắk Nông', 68 => 'Lâm Đồng',
        70 => 'Bình Phước', 72 => 'Tây Ninh', 74 => 'Bình Dương', 75 => 'Đồng Nai',
        77 => 'Bà Rịa - Vũng Tàu', 79 => 'TP. Hồ Chí Minh', 80 => 'Long An', 82 => 'Tiền Giang',
        83 => 'Bến Tre', 84 => 'Trà Vinh', 86 => 'Vĩnh Long', 87 => 'Đồng Tháp',
        89 => 'An Giang', 91 => 'Kiên Giang', 92 => 'Cần Thơ', 93 => 'Hậu Giang',
        94 => 'Sóc Trăng', 95 => 'Bạc Liêu', 96 => 'Cà Mau'
    ];

    // ─── Tax Code (MST) ──────────────────────────────────────────

    /**
     * Validate Vietnamese Tax Code (MST) according to Circular 105/2020/TT-BTC.
     * Supports 10-digit primary MST (Modulo 11 check digit) and 13-digit branch MST.
     */
    public static function isValidMst(string $input): bool
    {
        $clean = preg_replace('/[-\s]/', '', $input) ?? '';
        $len = strlen($clean);

        if ($len !== 10 && $len !== 13) {
            return false;
        }

        if (!ctype_digit($clean)) {
            return false;
        }

        // Validate 10-digit primary MST using Modulo 11
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $clean[$i]) * self::MST_WEIGHTS[$i];
        }

        $remainder = $sum % 11;
        $checkDigit = 10 - $remainder;

        if ($checkDigit === 10) {
            return false;
        }

        if ($checkDigit !== (int) $clean[9]) {
            return false;
        }

        // If 13-digit branch MST, branch suffix (last 3 digits) cannot be "000"
        if ($len === 13) {
            $branch = substr($clean, 10, 3);
            if ($branch === '000') {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize MST to standard canonical representation (e.g. '0100109106' or '0100109106-001').
     */
    public static function normalizeMst(string $input): ?string
    {
        if (!self::isValidMst($input)) {
            return null;
        }

        $clean = preg_replace('/[-\s]/', '', $input) ?? '';
        if (strlen($clean) === 10) {
            return $clean;
        }

        return substr($clean, 0, 10) . '-' . substr($clean, 10, 3);
    }

    // ─── Citizen ID (CCCD) ───────────────────────────────────────

    /**
     * Validate a 12-digit Vietnamese Citizen Identity Card (CCCD) and decode its metadata.
     */
    public static function decodeCccd(string $input): ?CccdInfo
    {
        $clean = preg_replace('/[\s.]/', '', $input) ?? '';

        if (strlen($clean) !== 12 || !ctype_digit($clean)) {
            return null;
        }

        // Province code: first 3 digits
        $provCode = (int) substr($clean, 0, 3);
        if (!isset(self::PROVINCES[$provCode])) {
            return null;
        }
        $provinceName = self::PROVINCES[$provCode];

        // Century & Gender: 4th digit
        $genderDigit = (int) $clean[3];
        $isMale = match ($genderDigit) {
            0, 2, 4, 6, 8 => true,
            1, 3, 5, 7, 9 => false,
            default => null,
        };
        if ($isMale === null) return null;

        $centuryBase = match ($genderDigit) {
            0, 1 => 1900,
            2, 3 => 2000,
            4, 5 => 2100,
            6, 7 => 2200,
            8, 9 => 2300,
            default => 0,
        };

        // Birth year: digits 5 & 6
        $yearShort = (int) substr($clean, 4, 2);
        $birthYear = $centuryBase + $yearShort;

        return new CccdInfo(
            id: $clean,
            birthYear: $birthYear,
            isMale: $isMale,
            provinceName: $provinceName
        );
    }

    /**
     * Fast boolean check if CCCD is valid.
     */
    public static function isValidCccd(string $input): bool
    {
        return self::decodeCccd($input) !== null;
    }

    // ─── Phone Number ────────────────────────────────────────────

    /**
     * Validate and normalize a Vietnamese mobile phone number (03x, 05x, 07x, 08x, 09x).
     *
     * @param string $input e.g. "0901234567", "+84 90 123 4567"
     * @param bool $international If true, formats as "+84901234567"; if false, formats as "0901234567".
     */
    public static function normalizePhone(string $input, bool $international = false): ?string
    {
        $clean = preg_replace('/[^\d+]/', '', $input) ?? '';

        // Handle leading +84 or 84
        if (str_starts_with($clean, '+84')) {
            $clean = '0' . substr($clean, 3);
        } elseif (str_starts_with($clean, '84') && strlen($clean) === 11) {
            $clean = '0' . substr($clean, 2);
        }

        if (strlen($clean) !== 10 || !str_starts_with($clean, '0')) {
            return null;
        }

        // Check valid Vietnamese mobile prefix: 03, 05, 07, 08, 09
        $prefix = substr($clean, 0, 2);
        if (!in_array($prefix, ['03', '05', '07', '08', '09'], true)) {
            return null;
        }

        if ($international) {
            return '+84' . substr($clean, 1);
        }

        return $clean;
    }
}
