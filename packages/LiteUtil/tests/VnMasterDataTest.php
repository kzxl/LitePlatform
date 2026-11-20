<?php

declare(strict_types=1);

namespace LiteUtil\Tests;

use PHPUnit\Framework\TestCase;
use LiteUtil\Validation\VnMasterData;

class VnMasterDataTest extends TestCase
{
    // ─── Tax Code (MST) ──────────────────────────────────────────

    public function testValid10DigitMst(): void
    {
        // 0100109106 is a well-known valid enterprise MST in Vietnam
        $this->assertTrue(VnMasterData::isValidMst('0100109106'));
        $this->assertTrue(VnMasterData::isValidMst('0100109106-001'));
        $this->assertSame('0100109106', VnMasterData::normalizeMst('0100109106'));
        $this->assertSame('0100109106-001', VnMasterData::normalizeMst('0100109106001'));
    }

    public function testInvalidMst(): void
    {
        // Bad check digit
        $this->assertFalse(VnMasterData::isValidMst('0100109107'));
        // Bad length
        $this->assertFalse(VnMasterData::isValidMst('123456789'));
        // Invalid branch 000
        $this->assertFalse(VnMasterData::isValidMst('0100109106000'));
    }

    // ─── Citizen ID (CCCD) ───────────────────────────────────────

    public function testDecodeValidCccd(): void
    {
        // 001: Hà Nội, 0: Male 1900s, 95: 1995, 012345
        $cccd1 = VnMasterData::decodeCccd('001095012345');
        $this->assertNotNull($cccd1);
        $this->assertSame('Hà Nội', $cccd1->provinceName);
        $this->assertSame(1995, $cccd1->birthYear);
        $this->assertTrue($cccd1->isMale);
        $this->assertSame('Nam', $cccd1->getGender());

        // 079: TP. Hồ Chí Minh, 1: Female 1900s, 98: 1998
        $cccd2 = VnMasterData::decodeCccd('079198000001');
        $this->assertNotNull($cccd2);
        $this->assertSame('TP. Hồ Chí Minh', $cccd2->provinceName);
        $this->assertSame(1998, $cccd2->birthYear);
        $this->assertFalse($cccd2->isMale);
        $this->assertSame('Nữ', $cccd2->getGender());

        // 038: Thanh Hóa, 2: Male 2000s, 02: 2002
        $cccd3 = VnMasterData::decodeCccd('038202000002');
        $this->assertNotNull($cccd3);
        $this->assertSame('Thanh Hóa', $cccd3->provinceName);
        $this->assertSame(2002, $cccd3->birthYear);
        $this->assertTrue($cccd3->isMale);
    }

    public function testInvalidCccd(): void
    {
        // Invalid province 999
        $this->assertNull(VnMasterData::decodeCccd('999095012345'));
        // Length 9 (old CMND)
        $this->assertNull(VnMasterData::decodeCccd('123456789'));
    }

    // ─── Phone Number ────────────────────────────────────────────

    public function testNormalizePhone(): void
    {
        $this->assertSame('0901234567', VnMasterData::normalizePhone('0901234567'));
        $this->assertSame('0901234567', VnMasterData::normalizePhone('+84 90 123 4567'));
        $this->assertSame('0901234567', VnMasterData::normalizePhone('84901234567'));
        $this->assertSame('+84901234567', VnMasterData::normalizePhone('0901234567', international: true));

        // Invalid carrier prefix (02 is landline, not mobile)
        $this->assertNull(VnMasterData::normalizePhone('0241234567'));
        // Wrong length
        $this->assertNull(VnMasterData::normalizePhone('09012345'));
    }
}
