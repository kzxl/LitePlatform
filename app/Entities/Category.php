<?php

declare(strict_types=1);

namespace App\Entities;

use DateTimeImmutable;
use LiteAdmin\Attribute\AdminColumn;
use LiteAdmin\Attribute\AdminField;
use LiteAdmin\Attribute\AdminResource;
use LiteORM\Attribute\AutoIncrement;
use LiteORM\Attribute\Column;
use LiteORM\Attribute\CreatedAt;
use LiteORM\Attribute\Entity;
use LiteORM\Attribute\Id;
use LiteORM\Attribute\Table;

/**
 * Universal Taxonomy Category entity (usable across Posts, Products, Projects, Services).
 */
#[Entity]
#[Table(name: 'categories')]
#[AdminResource(title: 'Categories', icon: 'folder', group: 'Content', order: 2)]
class Category
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 120)]
    #[AdminColumn(label: 'Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Category Name', required: true, placeholder: 'e.g. Tin tức công nghệ')]
    public string $name = '';

    #[Column(type: 'string', length: 150)]
    #[AdminColumn(label: 'Slug', sortable: true, searchable: true)]
    #[AdminField(label: 'URL Slug', type: 'slug', slugFrom: 'name', placeholder: 'auto-generated-from-name')]
    public string $slug = '';

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Description', type: 'textarea')]
    public ?string $description = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Created At')]
    public ?DateTimeImmutable $createdAt = null;
}
