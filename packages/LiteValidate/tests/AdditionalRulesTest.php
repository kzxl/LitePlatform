<?php

declare(strict_types=1);

namespace LiteValidate\Tests;

use PHPUnit\Framework\TestCase;
use LiteValidate\Validator;
use LiteValidate\Attribute\{PositiveOrZero, Numeric, Url, Regex};

class AdditionalRulesDto
{
    #[PositiveOrZero]
    public int $discount = 0;

    #[Numeric]
    public string $latitude = '16.0544';

    #[Url]
    public string $website = 'https://example.com';

    #[Regex('/^[A-Z]{3}-\d{4}$/', message: 'Code must match ABC-1234 format')]
    public string $serialCode = 'ABC-1234';
}

class AdditionalRulesTest extends TestCase
{
    public function testRulesPassWhenValid(): void
    {
        $data = [
            'discount' => 0,
            'latitude' => '16.0544',
            'website' => 'https://example.com',
            'serialCode' => 'ABC-1234',
        ];

        $result = Validator::validate($data, AdditionalRulesDto::class);
        $this->assertTrue($result->isValid());
    }

    public function testRulesFailWithCustomMessage(): void
    {
        $data = [
            'discount' => -5,
            'latitude' => 'invalid-geo',
            'website' => 'not-a-url',
            'serialCode' => 'bad_code',
        ];

        $result = Validator::validate($data, AdditionalRulesDto::class);
        $this->assertTrue($result->isFailed());

        $errors = $result->getErrors();
        $this->assertArrayHasKey('discount', $errors);
        $this->assertArrayHasKey('latitude', $errors);
        $this->assertArrayHasKey('website', $errors);
        $this->assertArrayHasKey('serialCode', $errors);
        $this->assertSame('Code must match ABC-1234 format', $errors['serialCode'][0]);
    }
}
