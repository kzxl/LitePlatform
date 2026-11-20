<?php

declare(strict_types=1);

namespace LiteUtil\Tests;

use PHPUnit\Framework\TestCase;
use LiteUtil\Number\NumberToWords;
use LiteUtil\Number\Formatter\VietnameseFormatter;
use LiteUtil\Number\Formatter\EnglishFormatter;

class NumberToWordsTest extends TestCase
{
    protected function setUp(): void
    {
        NumberToWords::reset();
    }

    // ─── Vietnamese Number to Words ──────────────────────────────────

    public function testVietnameseBasicNumbers(): void
    {
        $this->assertSame('không', NumberToWords::toWords(0, 'vi'));
        $this->assertSame('một', NumberToWords::toWords(1, 'vi'));
        $this->assertSame('năm', NumberToWords::toWords(5, 'vi'));
        $this->assertSame('mười', NumberToWords::toWords(10, 'vi'));
        $this->assertSame('mười một', NumberToWords::toWords(11, 'vi'));
        $this->assertSame('mười lăm', NumberToWords::toWords(15, 'vi'));
        $this->assertSame('hai mươi', NumberToWords::toWords(20, 'vi'));
        $this->assertSame('hai mươi mốt', NumberToWords::toWords(21, 'vi'));
        $this->assertSame('hai mươi lăm', NumberToWords::toWords(25, 'vi'));
    }

    public function testVietnameseHundredsAndThousands(): void
    {
        $this->assertSame('một trăm', NumberToWords::toWords(100, 'vi'));
        $this->assertSame('một trăm lẻ năm', NumberToWords::toWords(105, 'vi'));
        $this->assertSame('một trăm mười', NumberToWords::toWords(110, 'vi'));
        $this->assertSame('một trăm mười lăm', NumberToWords::toWords(115, 'vi'));
        $this->assertSame('một trăm hai mươi mốt', NumberToWords::toWords(121, 'vi'));
        $this->assertSame('một nghìn', NumberToWords::toWords(1000, 'vi'));
        $this->assertSame('một nghìn không trăm lẻ năm', NumberToWords::toWords(1005, 'vi'));
        $this->assertSame('một triệu hai trăm năm mươi nghìn', NumberToWords::toWords(1250000, 'vi'));
        $this->assertSame('một tỷ', NumberToWords::toWords(1000000000, 'vi'));
    }

    public function testVietnameseNegativeAndDecimals(): void
    {
        $this->assertSame('âm năm', NumberToWords::toWords(-5, 'vi'));
        $this->assertSame('mười hai phẩy năm', NumberToWords::toWords(12.5, 'vi'));
        $this->assertSame('không phẩy không năm', NumberToWords::toWords(0.05, 'vi'));
    }

    public function testVietnameseCurrency(): void
    {
        $this->assertSame(
            'một triệu hai trăm năm mươi nghìn đồng chẵn',
            NumberToWords::toCurrency(1250000, 'VND', 'vi')
        );
        $this->assertSame(
            'một trăm đô la Mỹ và năm mươi cent',
            NumberToWords::toCurrency(100.50, 'USD', 'vi')
        );
    }

    // ─── English Number to Words ─────────────────────────────────────

    public function testEnglishBasicNumbers(): void
    {
        $this->assertSame('zero', NumberToWords::toWords(0, 'en'));
        $this->assertSame('one', NumberToWords::toWords(1, 'en'));
        $this->assertSame('fifteen', NumberToWords::toWords(15, 'en'));
        $this->assertSame('twenty-one', NumberToWords::toWords(21, 'en'));
        $this->assertSame('one hundred', NumberToWords::toWords(100, 'en'));
        $this->assertSame('one hundred twenty-three', NumberToWords::toWords(123, 'en'));
        $this->assertSame('one thousand', NumberToWords::toWords(1000, 'en'));
        $this->assertSame('one million two hundred fifty thousand', NumberToWords::toWords(1250000, 'en'));
    }

    public function testEnglishNegativeAndDecimals(): void
    {
        $this->assertSame('minus five', NumberToWords::toWords(-5, 'en'));
        $this->assertSame('twelve point five', NumberToWords::toWords(12.5, 'en'));
    }

    public function testEnglishCurrency(): void
    {
        $this->assertSame(
            'one dollar',
            NumberToWords::toCurrency(1, 'USD', 'en')
        );
        $this->assertSame(
            'one hundred dollars and fifty cents',
            NumberToWords::toCurrency(100.50, 'USD', 'en')
        );
        $this->assertSame(
            'fifty euros and twenty cents',
            NumberToWords::toCurrency(50.20, 'EUR', 'en')
        );
    }

    // ─── Options & Capitalization ────────────────────────────────────

    public function testCapitalizeFirst(): void
    {
        $formatter = new VietnameseFormatter(capitalizeFirst: true);
        $this->assertSame('Một triệu đồng chẵn', $formatter->formatCurrency(1000000, 'VND'));

        $enFormatter = new EnglishFormatter(capitalizeFirst: true);
        $this->assertSame('One million dollars', $enFormatter->formatCurrency(1000000, 'USD'));
    }
}
