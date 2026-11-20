<?php

declare(strict_types=1);

namespace LiteUtil\Tests;

use PHPUnit\Framework\TestCase;
use LiteUtil\DateTime\VnDateTime;
use DateTimeImmutable;

class VnDateTimeTest extends TestCase
{
    public function testFormatVietnameseDates(): void
    {
        // 2026-03-09 was a Monday (Thứ Hai)
        $date = new DateTimeImmutable('2026-03-09 14:30:00');

        $human = VnDateTime::toHuman($date);
        $this->assertSame('Thứ Hai, ngày 09 tháng 03 năm 2026', $human);

        $custom = VnDateTime::format($date, 'l, d/m/Y');
        $this->assertSame('Thứ Hai, 09/03/2026', $custom);

        $short = VnDateTime::format($date, 'D, d/m/Y');
        $this->assertSame('T2, 09/03/2026', $short);

        $timeWords = VnDateTime::toTimeWords($date);
        $this->assertSame('14 giờ rưỡi', $timeWords);

        $date2 = new DateTimeImmutable('2026-03-09 15:00:00');
        $this->assertSame('15 giờ đúng', VnDateTime::toTimeWords($date2));

        $date3 = new DateTimeImmutable('2026-03-09 15:15:00');
        $this->assertSame('15 giờ 15 phút', VnDateTime::toTimeWords($date3));
    }

    public function testHolidaysAndWorkingDays(): void
    {
        $newYear = new DateTimeImmutable('2026-01-01');
        $this->assertTrue(VnDateTime::isHoliday($newYear));
        $this->assertFalse(VnDateTime::isWorkingDay($newYear));

        $liberationDay = new DateTimeImmutable('2026-04-30');
        $this->assertTrue(VnDateTime::isHoliday($liberationDay));

        // 2026-03-14 is a Saturday
        $sat = new DateTimeImmutable('2026-03-14');
        $this->assertTrue(VnDateTime::isWeekend($sat));
        $this->assertFalse(VnDateTime::isWorkingDay($sat));

        // 2026-03-09 is a normal Monday
        $mon = new DateTimeImmutable('2026-03-09');
        $this->assertTrue(VnDateTime::isWorkingDay($mon));

        // Add 5 working days from Monday 2026-03-09 -> Should jump past Saturday and Sunday to next Monday 2026-03-16
        $target = VnDateTime::addWorkingDays($mon, 5);
        $this->assertSame('2026-03-16', $target->format('Y-m-d'));

        // Diff in working days between Mon 09 and Fri 13 (inclusive) => 5 days
        $fri = new DateTimeImmutable('2026-03-13');
        $this->assertSame(5, VnDateTime::diffInWorkingDays($mon, $fri));
    }
}
