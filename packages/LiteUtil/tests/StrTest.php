<?php

declare(strict_types=1);

namespace LiteUtil\Tests;

use PHPUnit\Framework\TestCase;
use LiteUtil\Text\Str;

class StrTest extends TestCase
{
    public function testRemoveAccents(): void
    {
        $input = 'Học lập trình PHP tại Việt Nam & café';
        $expected = 'Hoc lap trinh PHP tai Viet Nam & cafe';
        $this->assertSame($expected, Str::removeAccents($input));
    }

    public function testSlug(): void
    {
        $input = 'Hướng Dẫn Lập Trình PHP 8.2 & Modern Features!';
        $this->assertSame('huong-dan-lap-trinh-php-8-2-modern-features', Str::slug($input));

        $custom = 'Sản phẩm mới 2026';
        $this->assertSame('san_pham_moi_2026', Str::slug($custom, '_'));
    }

    public function testCaseConversions(): void
    {
        $this->assertSame('orderItemDetail', Str::camel('order_item_detail'));
        $this->assertSame('orderItemDetail', Str::camel('order-item-detail'));

        $this->assertSame('OrderItemDetail', Str::pascal('order_item_detail'));
        $this->assertSame('OrderItemDetail', Str::pascal('order-item-detail'));

        $this->assertSame('order_item_detail', Str::snake('OrderItemDetail'));
        $this->assertSame('order-item-detail', Str::kebab('OrderItemDetail'));

        $this->assertSame('Hello World', Str::title('hello world'));
    }

    public function testTruncate(): void
    {
        $text = 'Xin chào tất cả các bạn đã đến với hệ thống';
        $this->assertSame('Xin chào...', Str::truncate($text, 8));
    }

    public function testMask(): void
    {
        $phone = '0912345678';
        $this->assertSame('091****678', Str::mask($phone, '*', 3, 4));

        $email = 'phong@example.com';
        $this->assertSame('p****@example.com', Str::mask($email, '*', 1, 4));
    }

    public function testStringSearch(): void
    {
        $this->assertTrue(Str::startsWith('https://google.com', ['http://', 'https://']));
        $this->assertFalse(Str::startsWith('ftp://google.com', ['http://', 'https://']));

        $this->assertTrue(Str::endsWith('document.pdf', ['.pdf', '.doc']));
        $this->assertFalse(Str::endsWith('document.png', ['.pdf', '.doc']));

        $this->assertTrue(Str::contains('ZeroPlatform Ecosystem', 'ecosystem', ignoreCase: true));
        $this->assertFalse(Str::contains('ZeroPlatform Ecosystem', 'ecosystem', ignoreCase: false));
    }
}
