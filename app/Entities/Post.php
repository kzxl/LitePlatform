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
 * SEO-ready Vietnamese Blog/Article entity with full 2-column WordPress/Filament Admin UX:
 * - Main: Title, Vietnamese Auto-Slug, Excerpt, WYSIWYG Rich Text Editor
 * - SEO Card: Meta Title & Meta Description with live Google SERP Preview + character counters
 * - Sidebar: Status Dropdown, Category Relation, Published Date, Featured Image (Upload + Media Library)
 */
#[Entity]
#[Table(name: 'posts')]
#[AdminResource(title: 'Blog Posts', icon: 'file-text', group: 'Content', order: 1)]
class Post
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Title', sortable: true, searchable: true)]
    #[AdminField(label: 'Article Title', required: true, section: 'main', placeholder: 'e.g. Hướng dẫn tối ưu SEO Shared Hosting')]
    public string $title = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Slug', sortable: true, searchable: true)]
    #[AdminField(label: 'URL Slug', type: 'slug', slugFrom: 'title', section: 'main', help: 'Auto-generated from title. Accessible at /blog/{slug}')]
    public string $slug = '';

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Excerpt / Summary', type: 'textarea', section: 'main', placeholder: 'Short summary shown on Blog listing...')]
    public ?string $excerpt = null;

    #[Column(type: 'text')]
    #[AdminField(label: 'Article Content', type: 'richtext', required: true, section: 'main')]
    public string $content = '';

    #[Column(name: 'meta_title', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'SEO Meta Title', section: 'seo', help: 'Recommended <= 60 characters')]
    public ?string $metaTitle = null;

    #[Column(name: 'meta_description', type: 'string', length: 320, nullable: true)]
    #[AdminField(label: 'SEO Meta Description', type: 'textarea', section: 'seo', help: 'Recommended <= 160 characters')]
    public ?string $metaDescription = null;

    #[Column(type: 'string', length: 20)]
    #[AdminColumn(label: 'Status', sortable: true)]
    #[AdminField(label: 'Status', type: 'select', options: ['draft' => 'Draft', 'published' => 'Published'], section: 'sidebar')]
    public string $status = 'draft';

    #[Column(name: 'category_id', type: 'integer', nullable: true)]
    #[AdminColumn(label: 'Category', sortable: true)]
    #[AdminField(label: 'Category', type: 'select', relation: 'categories:id:name', section: 'sidebar')]
    public ?int $categoryId = null;

    #[Column(name: 'published_at', type: 'datetime', nullable: true)]
    #[AdminColumn(label: 'Published At', sortable: true)]
    #[AdminField(label: 'Publish Date', type: 'datetime', section: 'sidebar')]
    public ?DateTimeImmutable $publishedAt = null;

    #[Column(name: 'featured_image', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'Featured Image', type: 'image', section: 'sidebar')]
    public ?string $featuredImage = null;

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
