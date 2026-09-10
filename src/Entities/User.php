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
#[Table(name: 'users')]
#[AdminResource(title: 'Users', icon: 'users', group: 'Administration')]
class User
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 100)]
    #[AdminColumn(label: 'Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Full Name', required: true, placeholder: 'e.g. John Doe')]
    public string $name = '';

    #[Column(type: 'string', length: 150)]
    #[AdminColumn(label: 'Email', sortable: true, searchable: true)]
    #[AdminField(label: 'Email Address', type: 'email', required: true, placeholder: 'e.g. john@example.com')]
    public string $email = '';

    #[Column(type: 'string', length: 50)]
    #[AdminColumn(label: 'Role', sortable: true)]
    #[AdminField(label: 'Role', type: 'text', placeholder: 'admin / user')]
    public string $role = 'user';

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Created At')]
    public ?string $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?string $updatedAt = null;
}
