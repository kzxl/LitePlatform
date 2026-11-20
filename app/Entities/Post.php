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
use LiteORM\Attribute\UpdatedAt;
use LiteUtil\Text\Str;

/**
 * SEO-ready Vietnamese Blog/Article entity managed directly via LiteAdmin Auto-CRUD.
 */
#[Entity]
#[Table(name: 'posts')]
#[AdminResource(title: 'Blog Posts', icon: 'file-text', group: 'Content')]
class Post
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Title', sortable: true, searchable: true)]
    #[AdminField(label: 'Article Title', required: true)]
    public string $title = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Slug', sortable: true, searchable: true)]
    #[AdminField(label: 'URL Slug', placeholder: 'auto-generated-from-title')]
    public string $slug = '';

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Excerpt / Summary', type: 'textarea')]
    public ?string $excerpt = null;

    #[Column(type: 'text')]
    #[AdminField(label: 'HTML Content', type: 'textarea', required: true)]
    public string $content = '';

    #[Column(name: 'featured_image', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'Featured Image URL')]
    public ?string $featuredImage = null;

    #[Column(name: 'meta_title', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'SEO Meta Title')]
    public ?string $metaTitle = null;

    #[Column(name: 'meta_description', type: 'string', length: 320, nullable: true)]
    #[AdminField(label: 'SEO Meta Description', type: 'textarea')]
    public ?string $metaDescription = null;

    #[Column(type: 'string', length: 20)]
    #[AdminColumn(label: 'Status', sortable: true)]
    #[AdminField(label: 'Status (draft / published)')]
    public string $status = 'draft';

    #[Column(name: 'published_at', type: 'datetime', nullable: true)]
    #[AdminColumn(label: 'Published At', sortable: true)]
    public ?DateTimeImmutable $publishedAt = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;

    /**
     * Generate a clean SEO slug from Vietnamese/UTF-8 title using LiteUtil\Text\Str.
     */
    public static function generateSlug(string $title): string
    {
        return Str::slug($title);
    }
}
