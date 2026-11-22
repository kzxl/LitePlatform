<?php

declare(strict_types=1);

namespace App\Modules\OrderExtension\Entities;

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
 * Unified Rental & Sale Order entity managed by OrderExtension.
 */
#[Entity]
#[Table(name: 'retail_orders')]
#[AdminResource(title: 'Orders & Rentals', icon: 'calendar', group: 'Orders', order: 1)]
#[Authorize(permissions: ['orders.read'], action: 'view')]
#[Authorize(permissions: ['orders.write'], action: 'create')]
#[Authorize(permissions: ['orders.write'], action: 'update')]
#[Authorize(permissions: ['orders.write'], action: 'delete')]
#[Authorize(permissions: ['reports.export'], action: 'export')]
class Order
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(name: 'order_code', type: 'string', length: 64)]
    #[AdminColumn(label: 'Order Code', sortable: true, searchable: true)]
    #[AdminField(label: 'Order Code', required: true, section: 'main', placeholder: 'e.g. ORD-20261122-01')]
    public string $orderCode = '';

    #[Column(name: 'order_type', type: 'string', length: 16)]
    #[AdminColumn(label: 'Type', sortable: true)]
    #[AdminField(label: 'Order Type', type: 'select', options: ['rental' => 'Rental', 'sale' => 'Sale'], required: true, section: 'main')]
    public string $orderType = 'rental';

    #[Column(name: 'customer_id', type: 'integer')]
    #[AdminColumn(label: 'Customer ID', sortable: true)]
    #[AdminField(label: 'Customer', type: 'select', relation: 'retail_customers:id:name', required: true, section: 'main')]
    public int $customerId = 0;

    #[Column(name: 'item_id', type: 'integer')]
    #[AdminColumn(label: 'Item ID', sortable: true)]
    #[AdminField(label: 'Catalog Item', type: 'select', relation: 'retail_items:id:name', required: true, section: 'main')]
    public int $itemId = 0;

    #[Column(type: 'string', length: 80, nullable: true)]
    #[AdminColumn(label: 'Variant')]
    #[AdminField(label: 'Variant / Size', section: 'main')]
    public ?string $variant = null;

    #[Column(name: 'start_date', type: 'string', length: 20)]
    #[AdminColumn(label: 'Order / Start Date', sortable: true)]
    #[AdminField(label: 'Order / Rental Start Date', type: 'date', required: true, section: 'main')]
    public string $startDate = '';

    #[Column(name: 'end_date', type: 'string', length: 20, nullable: true)]
    #[AdminColumn(label: 'Return Due')]
    #[AdminField(label: 'Expected Return Date (Rental)', type: 'date', section: 'main')]
    public ?string $endDate = null;

    #[Column(name: 'actual_return_date', type: 'string', length: 20, nullable: true)]
    #[AdminField(label: 'Actual Return Date', type: 'date', section: 'main')]
    public ?string $actualReturnDate = null;

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Order Notes', type: 'textarea', section: 'main')]
    public ?string $notes = null;

    #[Column(type: 'string', length: 20)]
    #[AdminColumn(label: 'Status', sortable: true)]
    #[AdminField(
        label: 'Status',
        type: 'select',
        options: [
            'pending'   => 'Pending',
            'confirmed' => 'Confirmed / Reserved',
            'active'    => 'Active (Out on Rental / Delivering)',
            'completed' => 'Completed / Returned',
            'cancelled' => 'Cancelled',
        ],
        required: true,
        section: 'sidebar'
    )]
    public string $status = 'confirmed';

    #[Column(type: 'float')]
    #[AdminColumn(label: 'Subtotal', sortable: true)]
    #[AdminField(label: 'Subtotal (VND)', type: 'number', required: true, section: 'sidebar')]
    public float $subtotal = 0.0;

    #[Column(type: 'float')]
    #[AdminField(label: 'Discount (VND)', type: 'number', section: 'sidebar')]
    public float $discount = 0.0;

    #[Column(name: 'extra_fees', type: 'float')]
    #[AdminField(label: 'Extra Fees — Late/Damage/Shipping (VND)', type: 'number', section: 'sidebar')]
    public float $extraFees = 0.0;

    #[Column(name: 'deposit_held', type: 'float')]
    #[AdminColumn(label: 'Deposit Held', sortable: true)]
    #[AdminField(label: 'Deposit Collected (VND)', type: 'number', section: 'sidebar', help: 'Refundable liability; excluded from net revenue.')]
    public float $depositHeld = 0.0;

    #[Column(name: 'deposit_refunded', type: 'float')]
    #[AdminField(label: 'Deposit Refunded (VND)', type: 'number', section: 'sidebar')]
    public float $depositRefunded = 0.0;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;
}
