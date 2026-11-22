<?php

declare(strict_types=1);

namespace App\Entities;

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

/**
 * Captured Contact / Quote Lead entity viewable in LiteAdmin.
 */
#[Entity]
#[Table(name: 'contact_leads')]
#[AdminResource(title: 'Contact Leads', icon: 'mail', group: 'CRM')]
#[Authorize(roles: ['admin', 'superadmin', 'manager', 'staff'])]
class ContactLead
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 120)]
    #[AdminColumn(label: 'Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Full Name', required: true)]
    public string $name = '';

    #[Column(type: 'string', length: 160)]
    #[AdminColumn(label: 'Email', sortable: true, searchable: true)]
    #[AdminField(label: 'Email', type: 'email', required: true)]
    public string $email = '';

    #[Column(type: 'string', length: 40, nullable: true)]
    #[AdminColumn(label: 'Phone', searchable: true)]
    #[AdminField(label: 'Phone')]
    public ?string $phone = null;

    #[Column(type: 'text')]
    #[AdminField(label: 'Message', type: 'textarea', required: true)]
    public string $message = '';

    #[Column(name: 'ip_address', type: 'string', length: 64, nullable: true)]
    #[AdminColumn(label: 'IP')]
    public ?string $ipAddress = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Received At', sortable: true)]
    public ?DateTimeImmutable $createdAt = null;
}
