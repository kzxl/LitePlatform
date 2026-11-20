<?php

declare(strict_types=1);

namespace LiteValidate\Tests;

use PHPUnit\Framework\TestCase;
use LiteValidate\Validator;
use LiteValidate\Attribute\{
    Required,
    RequiredIf,
    ValidateIf,
    Email,
    Length,
    Range,
    Positive,
    In,
    Regex,
    Nested
};

class OrderItemDto
{
    #[Required]
    public string $productId;

    #[Positive]
    public int $quantity;
}

class InvoiceDto
{
    #[Required]
    public string $type; // 'personal' or 'company'

    #[RequiredIf('type', 'company')]
    public ?string $taxNumber = null;

    #[RequiredIf('type', ['company', 'enterprise'])]
    public ?string $companyName = null;

    #[ValidateIf('type', 'company', new Email())]
    public ?string $companyEmail = null;

    #[ValidateIf('type', 'personal', new Length(min: 5, max: 20))]
    public ?string $personalIdNumber = null;

    #[Range(min: 1, max: 1000)]
    public int $amount = 10;

    #[In(['USD', 'VND', 'EUR'])]
    public string $currency = 'VND';

    #[Regex('/^[A-Z]{3}-[0-9]{4}$/')]
    public string $invoiceCode;

    #[Nested]
    public OrderItemDto $item;
}

class ConditionalValidationAndJsonSchemaTest extends TestCase
{
    public function testRequiredIfTriggersWhenConditionMet(): void
    {
        // When type is company, taxNumber and companyName are required
        $data = [
            'type' => 'company',
            'taxNumber' => '',
            'companyName' => null,
            'invoiceCode' => 'INV-1234',
            'item' => [
                'productId' => 'P001',
                'quantity' => 2,
            ],
        ];

        $result = Validator::validate($data, InvoiceDto::class);
        $this->assertTrue($result->isFailed());
        $errors = $result->getErrors();

        $this->assertArrayHasKey('taxNumber', $errors);
        $this->assertArrayHasKey('companyName', $errors);
        $this->assertStringContainsString('is required when type is company', $errors['taxNumber'][0]);
    }

    public function testRequiredIfPassesWhenConditionNotMet(): void
    {
        // When type is personal, taxNumber is not required
        $data = [
            'type' => 'personal',
            'taxNumber' => null,
            'companyName' => null,
            'personalIdNumber' => '12345678',
            'invoiceCode' => 'INV-1234',
            'item' => [
                'productId' => 'P001',
                'quantity' => 2,
            ],
        ];

        $result = Validator::validate($data, InvoiceDto::class);
        $this->assertTrue($result->isValid());

        /** @var InvoiceDto $dto */
        $dto = $result->validated();
        $this->assertSame('personal', $dto->type);
        $this->assertNull($dto->taxNumber);
        $this->assertSame('12345678', $dto->personalIdNumber);
    }

    public function testRequiredIfWithArrayOfValues(): void
    {
        // When type is enterprise, companyName is required
        $data = [
            'type' => 'enterprise',
            'companyName' => '',
            'invoiceCode' => 'INV-1234',
            'item' => [
                'productId' => 'P001',
                'quantity' => 2,
            ],
        ];

        $result = Validator::validate($data, InvoiceDto::class);
        $this->assertTrue($result->isFailed());
        $this->assertArrayHasKey('companyName', $result->getErrors());
    }

    public function testValidateIfAppliesInnerRuleOnlyWhenConditionMet(): void
    {
        // Condition met: type is company -> companyEmail must be valid email
        $dataInvalid = [
            'type' => 'company',
            'taxNumber' => 'TAX123',
            'companyName' => 'Acme Corp',
            'companyEmail' => 'not-an-email',
            'invoiceCode' => 'INV-1234',
            'item' => ['productId' => 'P001', 'quantity' => 1],
        ];

        $result = Validator::validate($dataInvalid, InvoiceDto::class);
        $this->assertTrue($result->isFailed());
        $this->assertArrayHasKey('companyEmail', $result->getErrors());
        $this->assertStringContainsString('valid email', $result->getErrors()['companyEmail'][0]);

        // Condition NOT met: type is personal -> companyEmail is not validated
        $dataValid = [
            'type' => 'personal',
            'companyEmail' => 'not-an-email',
            'personalIdNumber' => '12345678',
            'invoiceCode' => 'INV-1234',
            'item' => ['productId' => 'P001', 'quantity' => 1],
        ];

        $resultValid = Validator::validate($dataValid, InvoiceDto::class);
        $this->assertTrue($resultValid->isValid());
    }

    public function testToJsonSchemaGeneratesCompliantDraft07(): void
    {
        $schema = Validator::toJsonSchema(InvoiceDto::class, 'InvoiceSchema');

        $this->assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);
        $this->assertSame('object', $schema['type']);
        $this->assertSame('InvoiceSchema', $schema['title']);
        $this->assertContains('type', $schema['required']);
        $this->assertNotContains('taxNumber', $schema['required']); // Conditional, not in root required

        $props = $schema['properties'];
        $this->assertArrayHasKey('type', $props);
        $this->assertArrayHasKey('amount', $props);
        $this->assertSame('integer', $props['amount']['type']);
        $this->assertSame(1, $props['amount']['minimum']);
        $this->assertSame(1000, $props['amount']['maximum']);

        $this->assertArrayHasKey('currency', $props);
        $this->assertSame(['USD', 'VND', 'EUR'], $props['currency']['enum']);

        $this->assertArrayHasKey('invoiceCode', $props);
        $this->assertSame('^[A-Z]{3}-[0-9]{4}$', $props['invoiceCode']['pattern']);

        // Nested sub-schema
        $this->assertArrayHasKey('item', $props);
        $this->assertSame('object', $props['item']['type']);
        $this->assertSame('OrderItemDto', $props['item']['title']);
        $this->assertContains('productId', $props['item']['required']);
        $this->assertSame(1, $props['item']['properties']['quantity']['minimum']);
    }
}
