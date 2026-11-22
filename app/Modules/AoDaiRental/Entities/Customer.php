<?php

declare(strict_types=1);

namespace App\Modules\AoDaiRental\Entities;

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
use LiteORM\Attribute\UpdatedAt;

/**
 * Customer CRM entity for Ao Dai Rental shop (measurements, phone, Zalo, rental history).
 */
#[Entity]
#[Table(name: 'customers')]
#[AdminResource(title: 'Customers', icon: 'users', group: 'Ao Dai Rental', order: 2)]
class Customer
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 150)]
    #[AdminColumn(label: 'Full Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Full Name', required: true, section: 'main', placeholder: 'e.g. Nguyễn Thị Minh Anh')]
    public string $name = '';

    #[Column(type: 'string', length: 40)]
    #[AdminColumn(label: 'Phone', sortable: true, searchable: true)]
    #[AdminField(label: 'Phone Number', required: true, section: 'main', placeholder: '0909123456')]
    public string $phone = '';

    #[Column(type: 'string', length: 80, nullable: true)]
    #[AdminColumn(label: 'Zalo', searchable: true)]
    #[AdminField(label: 'Zalo ID / Phone', section: 'main', placeholder: '0909123456')]
    public ?string $zalo = null;

    #[Column(type: 'string', length: 160, nullable: true)]
    #[AdminColumn(label: 'Email', searchable: true)]
    #[AdminField(label: 'Email', type: 'email', section: 'main')]
    public ?string $email = null;

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Body Measurements', type: 'textarea', section: 'sidebar', placeholder: 'Chiều cao 1m62, 48kg, V1 84 - V2 64 - V3 90')]
    public ?string $measurements = null;

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Internal Notes', type: 'textarea', section: 'sidebar', placeholder: 'VIP customer, prefers pastel tones...')]
    public ?string $notes = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    #[AdminColumn(label: 'Joined', sortable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;
}
