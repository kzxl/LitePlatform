<?php

declare(strict_types=1);

namespace LiteValidate\Tests;

use PHPUnit\Framework\TestCase;
use LiteValidate\Validator;
use LiteValidate\Attribute\{Required, Email, Length, Range, Positive, In, Regex, Nested};
use LiteValidate\Exception\ValidationException;

class AddressDto
{
    #[Required]
    public string $city;

    #[Required, Length(min: 5, max: 6)]
    public string $postalCode;
}

class UserDto
{
    #[Required, Length(min: 3, max: 50)]
    public string $name;

    #[Required, Email]
    public string $email;

    #[Range(min: 18, max: 100)]
    public int $age;

    #[Positive]
    public float $salary;

    #[In(['ADMIN', 'USER', 'GUEST'])]
    public string $role = 'USER';

    #[Nested]
    public AddressDto $address;
}

class ValidatorTest extends TestCase
{
    public function testValidDtoPasses(): void
    {
        $data = [
            'name' => 'Phong Vo',
            'email' => 'phong@example.com',
            'age' => 28,
            'salary' => 1500.50,
            'role' => 'ADMIN',
            'address' => [
                'city' => 'Da Nang',
                'postalCode' => '550000',
            ],
        ];

        $result = Validator::validate($data, UserDto::class);
        $this->assertTrue($result->isValid());

        /** @var UserDto $dto */
        $dto = $result->validated();
        $this->assertInstanceOf(UserDto::class, $dto);
        $this->assertSame('Phong Vo', $dto->name);
        $this->assertSame('phong@example.com', $dto->email);
        $this->assertSame(28, $dto->age);
        $this->assertSame(1500.50, $dto->salary);
        $this->assertSame('ADMIN', $dto->role);
        $this->assertInstanceOf(AddressDto::class, $dto->address);
        $this->assertSame('Da Nang', $dto->address->city);
    }

    public function testValidationErrorsReportedCorrectly(): void
    {
        $data = [
            'name' => 'A', // Too short
            'email' => 'invalid-email',
            'age' => 15, // Below 18
            'salary' => -100, // Negative
            'role' => 'SUPERMAN', // Not in allowed list
            'address' => [
                'city' => '', // Required
                'postalCode' => '12', // Too short
            ],
        ];

        $result = Validator::validate($data, UserDto::class);
        $this->assertTrue($result->isFailed());

        $errors = $result->getErrors();
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('salary', $errors);
        $this->assertArrayHasKey('role', $errors);
        $this->assertArrayHasKey('address.city', $errors);
        $this->assertArrayHasKey('address.postalCode', $errors);

        $this->assertStringContainsString('at least 3 characters', $errors['name'][0]);
        $this->assertStringContainsString('valid email', $errors['email'][0]);
        $this->assertStringContainsString('at least 18', $errors['age'][0]);
    }

    public function testMakeThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);
        Validator::make(UserDto::class, ['name' => '']);
    }
}
