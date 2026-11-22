<?php

declare(strict_types=1);

namespace App\Modules\RetailExtension\Entities;

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
 * Domain-agnostic Retail & Rental Catalog Item entity.
 * Dynamic key-value attributes (`attributesJson`) adapt to any vertical
 * (apparel, formal wear, cameras, equipment, furniture, event props) without schema changes.
 */
#[Entity]
#[Table(name: 'retail_items')]
#[AdminResource(title: 'Catalog Items', icon: 'shopping-bag', group: 'Retail & Rental', order: 1)]
#[Authorize(permissions: ['retail.catalog.read'], action: 'view')]
#[Authorize(permissions: ['retail.catalog.write'], action: 'create')]
#[Authorize(permissions: ['retail.catalog.write'], action: 'update')]
#[Authorize(permissions: ['retail.catalog.write'], action: 'delete')]
#[Authorize(permissions: ['retail.catalog.read'], action: 'export')]
class CatalogItem
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(type: 'string', length: 64)]
    #[AdminColumn(label: 'SKU / Code', sortable: true, searchable: true)]
    #[AdminField(label: 'SKU / Item Code', required: true, section: 'main', placeholder: 'e.g. ITEM-001')]
    public string $sku = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Item Name', sortable: true, searchable: true)]
    #[AdminField(label: 'Item Name', required: true, section: 'main', placeholder: 'e.g. Silk Dress / DSLR Kit / Event Package')]
    public string $name = '';

    #[Column(type: 'string', length: 255)]
    #[AdminColumn(label: 'Slug', sortable: true)]
    #[AdminField(label: 'URL Slug', type: 'slug', slugFrom: 'name', required: true, section: 'main', help: 'Accessible at /catalog/{slug}')]
    public string $slug = '';

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Description', type: 'richtext', section: 'main')]
    public ?string $description = null;

    #[Column(name: 'gallery_json', type: 'text', nullable: true)]
    #[AdminField(label: 'Photo Gallery', type: 'gallery', section: 'main', help: 'Upload or select multiple product photos.')]
    public ?string $galleryJson = null;

    #[Column(name: 'attributes_json', type: 'text', nullable: true)]
    #[AdminField(label: 'Custom Attributes (Key / Value)', type: 'repeater', section: 'main', help: 'Add any vertical-specific specs (e.g. Color, Size, Material, Brand, Specs).')]
    public ?string $attributesJson = null;

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
            'active'      => 'Active (Visible)',
            'maintenance' => 'Maintenance / Restocking',
            'hidden'      => 'Hidden',
        ],
        section: 'sidebar'
    )]
    public string $status = 'active';

    #[Column(name: 'listing_type', type: 'string', length: 16)]
    #[AdminColumn(label: 'Mode', sortable: true)]
    #[AdminField(
        label: 'Listing Type',
        type: 'select',
        options: [
            'rent' => 'For Rent',
            'sale' => 'For Sale',
            'both' => 'Rent & Sale',
        ],
        required: true,
        section: 'sidebar'
    )]
    public string $listingType = 'rent';

    #[Column(name: 'category_id', type: 'integer', nullable: true)]
    #[AdminColumn(label: 'Category', sortable: true)]
    #[AdminField(label: 'Category', type: 'select', relation: 'categories:id:name', section: 'sidebar')]
    public ?int $categoryId = null;

    #[Column(type: 'float')]
    #[AdminColumn(label: 'Price', sortable: true)]
    #[AdminField(label: 'Price / Rental Rate (VND)', type: 'number', required: true, section: 'sidebar', placeholder: '450000')]
    public float $price = 0.0;

    #[Column(name: 'deposit_default', type: 'float')]
    #[AdminColumn(label: 'Deposit', sortable: true)]
    #[AdminField(label: 'Default Deposit (VND)', type: 'number', section: 'sidebar', placeholder: '0', help: 'Refundable security deposit for rental items (excluded from revenue).')]
    public float $depositDefault = 0.0;

    #[Column(name: 'variant_summary', type: 'string', length: 180, nullable: true)]
    #[AdminColumn(label: 'Variants', searchable: true)]
    #[AdminField(label: 'Variants / Sizes Summary', section: 'sidebar', placeholder: 'e.g. S, M, L · Red / Ivory')]
    public ?string $variantSummary = null;

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
