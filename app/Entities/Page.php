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
use LiteORM\Attribute\UpdatedAt;

/**
 * WordPress-style flexible Page entity (e.g. /gioi-thieu, /dich-vu, /bang-gia, /chinh-sach).
 * Automatically exposed at /{slug}, optionally displayed in top navigation, and included in /sitemap.xml.
 */
#[Entity]
#[Table(name: 'pages')]
#[AdminResource(title: 'Pages', icon: 'file', group: 'Content', order: 0)]
#[Authorize(roles: ['admin', 'superadmin', 'manager', 'staff'])]
class Page
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Title', sortable: true, searchable: true)]
    #[AdminField(label: 'Page Title', required: true, section: 'main', placeholder: 'e.g. Giới thiệu công ty')]
    public string $title = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Slug', sortable: true, searchable: true)]
    #[AdminField(label: 'URL Slug', type: 'slug', slugFrom: 'title', section: 'main', help: 'Accessible at /{slug}')]
    public string $slug = '';

    #[Column(type: 'text')]
    #[AdminField(label: 'Page Content', type: 'richtext', required: true, section: 'main')]
    public string $content = '';

    #[Column(name: 'meta_title', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'SEO Meta Title', section: 'seo', help: 'Recommended <= 60 characters')]
    public ?string $metaTitle = null;

    #[Column(name: 'meta_description', type: 'string', length: 320, nullable: true)]
    #[AdminField(label: 'SEO Meta Description', type: 'textarea', section: 'seo', help: 'Recommended <= 160 characters')]
    public ?string $metaDescription = null;

    #[Column(type: 'string', length: 20)]
    #[AdminColumn(label: 'Status', sortable: true)]
    #[AdminField(label: 'Status', type: 'select', options: ['published' => 'Published', 'draft' => 'Draft'], section: 'sidebar')]
    public string $status = 'published';

    #[Column(name: 'show_in_nav', type: 'boolean')]
    #[AdminColumn(label: 'In Navbar', sortable: true)]
    #[AdminField(label: 'Show in Top Navigation', type: 'checkbox', section: 'sidebar')]
    public bool $showInNav = true;

    #[Column(name: 'featured_image', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'Cover Image', type: 'image', section: 'sidebar')]
    public ?string $featuredImage = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;
}
