<?php

declare(strict_types=1);

namespace LiteUtil\Tests;

use PHPUnit\Framework\TestCase;
use LiteUtil\Security\IdObfuscator;

class IdObfuscatorTest extends TestCase
{
    public function testEncodeAndDecodeIsSymmetric(): void
    {
        $obfuscator = new IdObfuscator(salt: 'secret-key-123', minLength: 6);

        $testIds = [1, 2, 42, 100, 999, 10001, 888888, 2147483647];

        foreach ($testIds as $id) {
            $encoded = $obfuscator->encode($id);
            $this->assertGreaterThanOrEqual(6, strlen($encoded));

            $decoded = $obfuscator->decode($encoded);
            $this->assertSame($id, $decoded, "Failed for ID {$id}");
        }
    }

    public function testDifferentSaltsProduceDifferentOutputs(): void
    {
        $obf1 = new IdObfuscator(salt: 'salt-A');
        $obf2 = new IdObfuscator(salt: 'salt-B');

        $encoded1 = $obf1->encode(12345);
        $encoded2 = $obf2->encode(12345);

        $this->assertNotSame($encoded1, $encoded2);
    }

    public function testQuickStaticMethods(): void
    {
        $hash = IdObfuscator::quickEncode(500);
        $this->assertNotEmpty($hash);

        $decoded = IdObfuscator::quickDecode($hash);
        $this->assertSame(500, $decoded);
    }

    public function testInvalidHashReturnsNull(): void
    {
        $obfuscator = new IdObfuscator();
        $this->assertNull($obfuscator->decode(''));
        $this->assertNull($obfuscator->decode('invalid!chars#@!'));
    }
}
