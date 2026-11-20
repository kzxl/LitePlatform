<?php

declare(strict_types=1);

namespace App\Entities;

use LiteAdmin\Attribute\AdminColumn;
use LiteAdmin\Attribute\AdminField;
use LiteAdmin\Attribute\AdminResource;
use LiteORM\Attribute\AutoIncrement;
use LiteORM\Attribute\Column;
use LiteORM\Attribute\CreatedAt;
use LiteORM\Attribute\Entity;
use LiteORM\Attribute\Id;
use LiteORM\Attribute\Table;
use LiteORM\Attribute\UpdatedAt;

#[Entity]
#[Table(name: 'products')]
#[AdminResource(title: 'Products', icon: 'box', group: 'Management')]
class Product
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string')]
    #[AdminColumn(label: 'Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Name', type: 'text', required: true)]
    public string $name = '';

    #[Column(type: 'float')]
    #[AdminColumn(label: 'Price', sortable: true, searchable: true)]
    #[AdminField(label: 'Price', type: 'number', required: true)]
    public float $price = 0.0;

    #[Column(type: 'integer')]
    #[AdminColumn(label: 'Stock', sortable: true, searchable: true)]
    #[AdminField(label: 'Stock', type: 'number', required: true)]
    public int $stock = 0;

    #[Column(type: 'boolean')]
    #[AdminColumn(label: 'Is Active', sortable: true, searchable: true)]
    #[AdminField(label: 'Is Active', type: 'checkbox', required: true)]
    public bool $is_active = false;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Created At')]
    public ?\DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?\DateTimeImmutable $updatedAt = null;
}
