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
 * Database-backed SEO 301/302 Redirect entity managed via LiteAdmin.
 */
#[Entity]
#[Table(name: 'redirects')]
#[AdminResource(title: 'SEO Redirects', icon: 'corner-up-right', group: 'SEO')]
#[Authorize(roles: ['admin', 'superadmin', 'manager'])]
class Redirect
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(name: 'source_path', type: 'string', length: 255)]
    #[AdminColumn(label: 'Old Path', sortable: true, searchable: true)]
    #[AdminField(label: 'Old Path (e.g. /old-slug)', required: true)]
    public string $sourcePath = '';

    #[Column(name: 'target_url', type: 'string', length: 500)]
    #[AdminColumn(label: 'Target URL', searchable: true)]
    #[AdminField(label: 'New Target Path or URL', required: true)]
    public string $targetUrl = '';

    #[Column(name: 'status_code', type: 'integer')]
    #[AdminColumn(label: 'HTTP Status', sortable: true)]
    #[AdminField(label: 'Status Code (301 or 302)')]
    public int $statusCode = 301;

    #[Column(name: 'is_active', type: 'boolean')]
    #[AdminColumn(label: 'Active', sortable: true)]
    #[AdminField(label: 'Active')]
    public bool $isActive = true;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    public ?DateTimeImmutable $createdAt = null;
}
