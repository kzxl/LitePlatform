<?php

declare(strict_types=1);

namespace App\Modules\AoDaiRental\Services;

use PDO;

/**
 * Date-overlap availability checker preventing double-booking of Ao Dai garments.
 * Active bookings are orders with status IN ('booked', 'picked_up').
 */
final class AvailabilityService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Check whether an Ao Dai design is free for the entire inclusive date range [rentDate, returnDate].
     */
    public function isAvailable(
        int $aoDaiId,
        string $rentDate,
        string $returnDate,
        ?int $excludeOrderId = null
    ): bool {
        return count($this->findConflicts($aoDaiId, $rentDate, $returnDate, $excludeOrderId)) === 0;
    }

    /**
     * Find conflicting active orders overlapping [rentDate, returnDate].
     *
     * @return list<array<string, mixed>>
     */
    public function findConflicts(
        int $aoDaiId,
        string $rentDate,
        string $returnDate,
        ?int $excludeOrderId = null
    ): array {
        if ($rentDate === '' || $returnDate === '' || $returnDate < $rentDate) {
            return [];
        }

        $sql = "
            SELECT DISTINCT o.id, o.order_code, o.customer_id, o.rent_date, o.return_date, o.status
            FROM rental_orders o
            LEFT JOIN rental_order_items i ON i.rental_order_id = o.id
            WHERE (o.ao_dai_id = :ao_dai_id OR i.ao_dai_id = :ao_dai_id_item)
              AND o.status IN ('booked', 'picked_up')
              AND o.rent_date <= :return_date
              AND o.return_date >= :rent_date
        ";

        $params = [
            ':ao_dai_id'      => $aoDaiId,
            ':ao_dai_id_item' => $aoDaiId,
            ':rent_date'      => $rentDate,
            ':return_date'    => $returnDate,
        ];

        if ($excludeOrderId !== null) {
            $sql .= ' AND o.id != :exclude_id';
            $params[':exclude_id'] = $excludeOrderId;
        }

        $sql .= ' ORDER BY o.rent_date ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
