<?php

declare(strict_types=1);

namespace App\DTOs;

use LiteValidate\Attribute\Required;

class CreateProductRequest
{
    #[Required]
    public string $name;

    #[Required]
    public float $price;

    #[Required]
    public int $stock;

    #[Required]
    public bool $is_active;
}