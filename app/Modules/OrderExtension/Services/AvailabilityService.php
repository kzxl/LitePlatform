<?php

declare(strict_types=1);

namespace App\Modules\OrderExtension\Services;

use PDO;

/**
 * Rental date-overlap availability checker preventing double-booking.
 */
final class AvailabilityService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isAvailable(int $itemId, string $startDate, string $endDate, ?int $excludeOrderId = null): bool
    {
        return count($this->findConflicts($itemId, $startDate, $endDate, $excludeOrderId)) === 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findConflicts(int $itemId, string $startDate, string $endDate, ?int $excludeOrderId = null): array
    {
        if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
            return [];
        }

        $sql = "
            SELECT DISTINCT o.id, o.order_code, o.customer_id, o.start_date, o.end_date, o.status
            FROM retail_orders o
            LEFT JOIN retail_order_items i ON i.order_id = o.id
            WHERE (o.item_id = :item_id OR i.item_id = :item_id_sub)
              AND o.order_type = 'rental'
              AND o.status IN ('confirmed', 'active')
              AND o.start_date <= :end_date
              AND COALESCE(o.end_date, o.start_date) >= :start_date
        ";

        $params = [
            ':item_id'     => $itemId,
            ':item_id_sub' => $itemId,
            ':start_date'  => $startDate,
            ':end_date'    => $endDate,
        ];

        if ($excludeOrderId !== null) {
            $sql .= ' AND o.id != :exclude_id';
            $params[':exclude_id'] = $excludeOrderId;
        }

        $sql .= ' ORDER BY o.start_date ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
