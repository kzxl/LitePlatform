<?php

declare(strict_types=1);

namespace LiteUtil\DateTime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Vietnamese DateTime helper: timezone Asia/Ho_Chi_Minh, Vietnamese day/month names,
 * national holidays, and working days calculations.
 */
class VnDateTime
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    private const DAYS = [
        0 => 'Chủ Nhật',
        1 => 'Thứ Hai',
        2 => 'Thứ Ba',
        3 => 'Thứ Tư',
        4 => 'Thứ Năm',
        5 => 'Thứ Sáu',
        6 => 'Thứ Bảy',
    ];

    private const SHORT_DAYS = [
        0 => 'CN',
        1 => 'T2',
        2 => 'T3',
        3 => 'T4',
        4 => 'T5',
        5 => 'T6',
        6 => 'T7',
    ];

    private const MONTHS = [
        1 => 'Tháng 1',
        2 => 'Tháng 2',
        3 => 'Tháng 3',
        4 => 'Tháng 4',
        5 => 'Tháng 5',
        6 => 'Tháng 6',
        7 => 'Tháng 7',
        8 => 'Tháng 8',
        9 => 'Tháng 9',
        10 => 'Tháng 10',
        11 => 'Tháng 11',
        12 => 'Tháng 12',
    ];

    /** Fixed national public holidays in Vietnam: [m-d] */
    private const FIXED_HOLIDAYS = [
        '01-01', // Tết Dương lịch
        '04-30', // Ngày Giải phóng miền Nam
        '05-01', // Ngày Quốc tế Lao động
        '09-02', // Quốc khánh Việt Nam
        '09-03', // Nghỉ liền kề Quốc khánh
    ];

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    /**
     * Format a date into full Vietnamese prose.
     * E.g.: "Thứ Hai, ngày 15 tháng 03 năm 2026"
     */
    public static function toHuman(DateTimeInterface $date): string
    {
        $dt = self::toImmutable($date);
        $dayOfWeek = self::DAYS[(int) $dt->format('w')];
        $day = $dt->format('d');
        $month = $dt->format('m');
        $year = $dt->format('Y');

        return "{$dayOfWeek}, ngày {$day} tháng {$month} năm {$year}";
    }

    /**
     * Format a date with Vietnamese day of week and month support.
     * E.g. 'l, d/m/Y' => 'Thứ Hai, 15/03/2026'
     * E.g. 'D, d/m/Y' => 'T2, 15/03/2026'
     */
    public static function format(DateTimeInterface $date, string $format = 'l, d/m/Y'): string
    {
        $dt = self::toImmutable($date);
        $w = (int) $dt->format('w');
        $m = (int) $dt->format('n');

        $output = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $char = $format[$i];
            if ($char === '\\' && $i + 1 < $len) {
                $output .= $format[++$i];
                continue;
            }
            $output .= match ($char) {
                'l' => self::DAYS[$w],
                'D' => self::SHORT_DAYS[$w],
                'F' => self::MONTHS[$m],
                'M' => self::MONTHS[$m],
                default => $dt->format($char),
            };
        }

        return $output;
    }

    /**
     * Format time in Vietnamese words: e.g. "14 giờ 30 phút"
     */
    public static function toTimeWords(DateTimeInterface $date): string
    {
        $dt = self::toImmutable($date);
        $hour = (int) $dt->format('H');
        $minute = (int) $dt->format('i');

        if ($minute === 0) {
            return "{$hour} giờ đúng";
        }
        if ($minute === 30) {
            return "{$hour} giờ rưỡi";
        }

        return "{$hour} giờ {$minute} phút";
    }

    /**
     * Check if a given date is a weekend (Saturday or Sunday).
     */
    public static function isWeekend(DateTimeInterface $date): bool
    {
        $w = (int) self::toImmutable($date)->format('w');
        return $w === 0 || $w === 6;
    }

    /**
     * Check if a given date is a Vietnamese public holiday.
     * Checks fixed solar calendar holidays.
     */
    public static function isHoliday(DateTimeInterface $date): bool
    {
        $md = self::toImmutable($date)->format('m-d');
        return in_array($md, self::FIXED_HOLIDAYS, true);
    }

    /**
     * Check if a given date is a business/working day (neither weekend nor holiday).
     */
    public static function isWorkingDay(DateTimeInterface $date): bool
    {
        return !self::isWeekend($date) && !self::isHoliday($date);
    }

    /**
     * Add N working days to a date, skipping weekends and holidays.
     */
    public static function addWorkingDays(DateTimeInterface $date, int $days): DateTimeImmutable
    {
        $current = self::toImmutable($date);
        $step = $days >= 0 ? 1 : -1;
        $remaining = abs($days);

        while ($remaining > 0) {
            $current = $current->modify("{$step} day");
            if (self::isWorkingDay($current)) {
                $remaining--;
            }
        }

        return $current;
    }

    /**
     * Count the number of working days between two dates (inclusive of start and end).
     */
    public static function diffInWorkingDays(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $start = self::toImmutable($from)->setTime(0, 0, 0);
        $end = self::toImmutable($to)->setTime(0, 0, 0);

        if ($start > $end) {
            return -self::diffInWorkingDays($end, $start);
        }

        $count = 0;
        $cursor = $start;
        while ($cursor <= $end) {
            if (self::isWorkingDay($cursor)) {
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }

    private static function toImmutable(DateTimeInterface $date): DateTimeImmutable
    {
        if ($date instanceof DateTimeImmutable) {
            return $date;
        }
        return DateTimeImmutable::createFromInterface($date);
    }
}
