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
 * Rental Order entity separating Rental Revenue (rentalFee - discount + lateFee + damageFee)
 * from Refundable Security Deposits (depositHeld - depositRefunded).
 */
#[Entity]
#[Table(name: 'rental_orders')]
#[AdminResource(title: 'Rental Orders', icon: 'calendar', group: 'Ao Dai Rental', order: 3)]
class RentalOrder
{
    #[Id]
    #[AutoIncrement]
    #[Column(type: 'integer')]
    #[AdminColumn(label: 'ID', sortable: true)]
    public ?int $id = null;

    #[Column(name: 'order_code', type: 'string', length: 64)]
    #[AdminColumn(label: 'Order Code', sortable: true, searchable: true)]
    #[AdminField(label: 'Order Code', required: true, section: 'main', placeholder: 'e.g. RO-20261122-01')]
    public string $orderCode = '';

    #[Column(name: 'customer_id', type: 'integer')]
    #[AdminColumn(label: 'Customer ID', sortable: true)]
    #[AdminField(label: 'Customer', type: 'select', relation: 'customers:id:name', required: true, section: 'main')]
    public int $customerId = 0;

    #[Column(name: 'ao_dai_id', type: 'integer')]
    #[AdminColumn(label: 'Ao Dai ID', sortable: true)]
    #[AdminField(label: 'Ao Dai Design', type: 'select', relation: 'ao_dais:id:name', required: true, section: 'main')]
    public int $aoDaiId = 0;

    #[Column(type: 'string', length: 32, nullable: true)]
    #[AdminColumn(label: 'Size')]
    #[AdminField(label: 'Size', section: 'main', placeholder: 'M')]
    public ?string $size = null;

    #[Column(name: 'rent_date', type: 'string', length: 20)]
    #[AdminColumn(label: 'Rent Date', sortable: true)]
    #[AdminField(label: 'Rent Date', type: 'date', required: true, section: 'main')]
    public string $rentDate = '';

    #[Column(name: 'return_date', type: 'string', length: 20)]
    #[AdminColumn(label: 'Return Date', sortable: true)]
    #[AdminField(label: 'Expected Return Date', type: 'date', required: true, section: 'main')]
    public string $returnDate = '';

    #[Column(name: 'actual_return_date', type: 'string', length: 20, nullable: true)]
    #[AdminColumn(label: 'Returned On')]
    #[AdminField(label: 'Actual Return Date', type: 'date', section: 'main')]
    public ?string $actualReturnDate = null;

    #[Column(type: 'text', nullable: true)]
    #[AdminField(label: 'Order Notes', type: 'textarea', section: 'main', placeholder: 'Pin adjustment, accessories included...')]
    public ?string $notes = null;

    #[Column(type: 'string', length: 20)]
    #[AdminColumn(label: 'Status', sortable: true)]
    #[AdminField(
        label: 'Order Status',
        type: 'select',
        options: [
            'booked'    => 'Booked (Reserved)',
            'picked_up' => 'Picked Up (Out on Rental)',
            'returned'  => 'Returned (Completed)',
            'cancelled' => 'Cancelled',
        ],
        required: true,
        section: 'sidebar'
    )]
    public string $status = 'booked';

    #[Column(name: 'rental_fee', type: 'float')]
    #[AdminColumn(label: 'Rental Fee', sortable: true)]
    #[AdminField(label: 'Rental Fee (VND)', type: 'number', required: true, section: 'sidebar', placeholder: '450000')]
    public float $rentalFee = 0.0;

    #[Column(type: 'float')]
    #[AdminField(label: 'Discount (VND)', type: 'number', section: 'sidebar', placeholder: '0')]
    public float $discount = 0.0;

    #[Column(name: 'late_fee', type: 'float')]
    #[AdminField(label: 'Late Return Fee (VND)', type: 'number', section: 'sidebar', placeholder: '0')]
    public float $lateFee = 0.0;

    #[Column(name: 'damage_fee', type: 'float')]
    #[AdminField(label: 'Damage / Repair Fee (VND)', type: 'number', section: 'sidebar', placeholder: '0')]
    public float $damageFee = 0.0;

    #[Column(name: 'deposit_held', type: 'float')]
    #[AdminColumn(label: 'Deposit Held', sortable: true)]
    #[AdminField(label: 'Deposit Collected (VND)', type: 'number', section: 'sidebar', placeholder: '500000', help: 'Tracked as refundable liability, never counted as revenue.')]
    public float $depositHeld = 0.0;

    #[Column(name: 'deposit_refunded', type: 'float')]
    #[AdminField(label: 'Deposit Refunded (VND)', type: 'number', section: 'sidebar', placeholder: '0')]
    public float $depositRefunded = 0.0;

    #[Column(name: 'created_at', type: 'datetime')]
    #[CreatedAt]
    public ?DateTimeImmutable $createdAt = null;

    #[Column(name: 'updated_at', type: 'datetime', nullable: true)]
    #[UpdatedAt]
    public ?DateTimeImmutable $updatedAt = null;
}
