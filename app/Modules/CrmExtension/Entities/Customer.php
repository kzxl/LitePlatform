<?php

declare(strict_types=1);

namespace App\Modules\CrmExtension\Entities;

use DateTimeImmutable;
use LiteAdmin\Attribute\AdminColumn;
use LiteAdmin\Attribute\AdminField;
use LiteAdmin\Attribute\AdminResource;
use LiteAdmin\Attribute\Authorize;
use LiteORM\Attribute\AutoIncrement;
use LiteORM\Attribute\Column;
use LiteORM\Attribute\CreatedAt;
use LiteORM\Attribute\Entity;
use LiteORM\Attribute\Id;
use LiteORM\Attribute\Table;
use LiteORM\Attribute\UpdatedAt;

/**
 * Standalone Customer CRM entity managed by CrmExtension.
 */
#[Entity]
#[Table(name: 'retail_customers')]
#[AdminResource(title: 'Customers', icon: 'users', group: 'CRM', order: 1)]
#[Authorize(permissions: ['crm.customers.read'], action: 'view')]
#[Authorize(permissions: ['crm.customers.write'], action: 'create')]
#[Authorize(permissions: ['crm.customers.write'], action: 'update')]
#[Authorize(permissions: ['crm.customers.write'], action: 'delete')]
#[Authorize(permissions: ['crm.customers.read'], action: 'export')]
class Customer
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 150)]
    #[AdminColumn(label: 'Full Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Full Name', required: true, section: 'main')]
    public string $name = '';

    #[Column(type: 'string', length: 40)]
    #[AdminColumn(label: 'Phone', sortable: true, searchable: true)]
    #[AdminField(label: 'Phone Number', required: true, section: 'main')]
    public string $phone = '';

    #[Column(name: 'chat_handle', type: 'string', length: 120, nullable: true)]
    #[AdminColumn(label: 'Zalo / Chat', searchable: true)]
    #[AdminField(label: 'Zalo / Messenger / Chat ID', section: 'main')]
    public ?string $chatHandle = null;

    #[Column(type: 'string', length: 160, nullable: true)]
    #[AdminColumn(label: 'Email', searchable: true)]
    #[AdminField(label: 'Email', type: 'email', section: 'main')]
    public ?string $email = null;

    #[Column(name: 'profile_specs', type: 'text', nullable: true)]
    #[AdminField(label: 'Customer Profile / Specs', type: 'textarea', section: 'sidebar')]
    public ?string $profileSpecs = null;

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Internal Notes', type: 'textarea', section: 'sidebar')]
    public ?string $notes = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Created', sortable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;
}
