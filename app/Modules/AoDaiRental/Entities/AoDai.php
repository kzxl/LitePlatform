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
 * Ao Dai garment catalog entity managed inside the AoDaiRental plugin.
 */
#[Entity]
#[Table(name: 'ao_dais')]
#[AdminResource(title: 'Ao Dai Catalog', icon: 'sparkles', group: 'Ao Dai Rental', order: 1)]
class AoDai
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 64)]
    #[AdminColumn(label: 'Code', sortable: true, searchable: true)]
    #[AdminField(label: 'Design Code', required: true, section: 'main', placeholder: 'e.g. AD-001')]
    public string $code = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Design Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Design Name', required: true, section: 'main', placeholder: 'e.g. Áo Dài Lụa Tơ Tằm Đỏ Đô Thêu Sen')]
    public string $name = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Slug', sortable: true)]
    #[AdminField(label: 'URL Slug', type: 'slug', slugFrom: 'name', required: true, section: 'main', help: 'Accessible at /mau-ao-dai/{slug}')]
    public string $slug = '';

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Description & Care Notes', type: 'richtext', section: 'main')]
    public ?string $description = null;

    #[Column(name: 'gallery_json', type: 'text', nullable: true)]
    #[AdminField(label: 'Photo Gallery', type: 'gallery', section: 'main', help: 'Upload or pick multiple angles of this Ao Dai design.')]
    public ?string $galleryJson = null;

    #[Column(name: 'meta_title', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'SEO Meta Title', section: 'seo', help: 'Recommended <= 60 characters')]
    public ?string $metaTitle = null;

    #[Column(name: 'meta_description', type: 'string', length: 320, nullable: true)]
    #[AdminField(label: 'SEO Meta Description', type: 'textarea', section: 'seo', help: 'Recommended <= 160 characters')]
    public ?string $metaDescription = null;

    #[Column(type: 'string', length: 20)]
    #[AdminColumn(label: 'Status', sortable: true)]
    #[AdminField(
        label: 'Status',
        type: 'select',
        options: [
            'available'   => 'Available',
            'maintenance' => 'Maintenance / Cleaning',
            'hidden'      => 'Hidden',
        ],
        section: 'sidebar'
    )]
    public string $status = 'available';

    #[Column(name: 'category_id', type: 'integer', nullable: true)]
    #[AdminColumn(label: 'Category', sortable: true)]
    #[AdminField(label: 'Category', type: 'select', relation: 'categories:id:name', section: 'sidebar')]
    public ?int $categoryId = null;

    #[Column(name: 'rental_price', type: 'float')]
    #[AdminColumn(label: 'Rental Price (VND)', sortable: true)]
    #[AdminField(label: 'Rental Price (VND)', type: 'number', required: true, section: 'sidebar', placeholder: '450000')]
    public float $rentalPrice = 0.0;

    #[Column(name: 'deposit_default', type: 'float')]
    #[AdminColumn(label: 'Deposit (VND)', sortable: true)]
    #[AdminField(label: 'Default Deposit (VND)', type: 'number', section: 'sidebar', placeholder: '500000', help: 'Refundable security deposit (excluded from revenue).')]
    public float $depositDefault = 0.0;

    #[Column(type: 'string', length: 80, nullable: true)]
    #[AdminColumn(label: 'Color', sortable: true, searchable: true)]
    #[AdminField(label: 'Color', section: 'sidebar', placeholder: 'e.g. Đỏ đô, Trắng kem, Hồng pastel')]
    public ?string $color = null;

    #[Column(type: 'string', length: 120, nullable: true)]
    #[AdminColumn(label: 'Sizes')]
    #[AdminField(label: 'Available Sizes', section: 'sidebar', placeholder: 'S, M, L, XL')]
    public ?string $sizes = null;

    #[Column(type: 'string', length: 120, nullable: true)]
    #[AdminField(label: 'Material', section: 'sidebar', placeholder: 'e.g. Lụa tơ tằm, Gấm, Voan tơ')]
    public ?string $material = null;

    #[Column(name: 'featured_image', type: 'string', length: 255, nullable: true)]
    #[AdminField(label: 'Cover Photo', type: 'image', section: 'sidebar')]
    public ?string $featuredImage = null;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;
}
