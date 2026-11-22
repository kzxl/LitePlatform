<?php

declare(strict_types=1);

namespace App\Modules\AoDaiRental\Services;

use PDO;

/**
 * Accurate accounting revenue report service for Ao Dai Rental operations.
 *
 * Accounting invariants:
 * - Net Revenue = SUM(MAX(0, rental_fee - discount) + late_fee + damage_fee)
 *   for orders with status IN ('booked', 'picked_up', 'returned').
 * - Cancelled orders are strictly excluded from revenue.
 * - Refundable deposits (deposit_held - deposit_refunded) are tracked as Liability,
 *   NEVER mixed into Net Revenue.
 */
final class RevenueReportService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Build complete financial summary, daily trend, top garments, top customers & overdue count.
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   kpis: array{
     *     net_revenue: float,
     *     gross_rental_fee: float,
     *     total_discount: float,
     *     total_extra_fees: float,
     *     deposit_held_period: float,
     *     deposit_refunded_period: float,
     *     deposit_currently_held: float,
     *     total_orders: int,
     *     cancelled_orders: int,
     *     overdue_orders: int
     *   },
     *   daily: list<array{date: string, orders: int, net_revenue: float, deposit_held: float}>,
     *   top_garments: list<array{id: int, code: string, name: string, rentals: int, net_revenue: float}>,
     *   top_customers: list<array{id: int, name: string, phone: string, orders: int, total_spend: float}>
     * }
     */
    public function buildReport(string $from, string $to, ?string $today = null): array
    {
        $todayDate = $today ?? date('Y-m-d');

        // 1. Fetch orders in date range [from, to]
        $stmt = $this->pdo->prepare("
            SELECT o.*, a.code AS ao_dai_code, a.name AS ao_dai_name,
                   c.name AS customer_name, c.phone AS customer_phone
            FROM rental_orders o
            LEFT JOIN ao_dais a ON a.id = o.ao_dai_id
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.rent_date >= :from_date AND o.rent_date <= :to_date
            ORDER BY o.rent_date ASC, o.id ASC
        ");
        $stmt->execute([
            ':from_date' => $from,
            ':to_date'   => $to,
        ]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $netRevenue = 0.0;
        $grossRental = 0.0;
        $totalDiscount = 0.0;
        $totalExtraFees = 0.0;
        $depositHeldPeriod = 0.0;
        $depositRefundedPeriod = 0.0;
        $totalOrders = 0;
        $cancelledOrders = 0;

        $dailyMap = [];
        $garmentMap = [];
        $customerMap = [];

        foreach ($orders as $order) {
            $status = (string)($order['status'] ?? 'booked');
            if ($status === 'cancelled') {
                $cancelledOrders++;
                continue;
            }

            $totalOrders++;
            $rentalFee = (float)($order['rental_fee'] ?? 0);
            $discount = (float)($order['discount'] ?? 0);
            $lateFee = (float)($order['late_fee'] ?? 0);
            $damageFee = (float)($order['damage_fee'] ?? 0);
            $depHeld = (float)($order['deposit_held'] ?? 0);
            $depRef = (float)($order['deposit_refunded'] ?? 0);

            $orderNet = max(0.0, $rentalFee - $discount) + $lateFee + $damageFee;

            $netRevenue += $orderNet;
            $grossRental += $rentalFee;
            $totalDiscount += $discount;
            $totalExtraFees += ($lateFee + $damageFee);
            $depositHeldPeriod += $depHeld;
            $depositRefundedPeriod += $depRef;

            $day = substr((string)$order['rent_date'], 0, 10);
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = ['date' => $day, 'orders' => 0, 'net_revenue' => 0.0, 'deposit_held' => 0.0];
            }
            $dailyMap[$day]['orders']++;
            $dailyMap[$day]['net_revenue'] += $orderNet;
            $dailyMap[$day]['deposit_held'] += $depHeld;

            $gid = (int)$order['ao_dai_id'];
            if (!isset($garmentMap[$gid])) {
                $garmentMap[$gid] = [
                    'id'          => $gid,
                    'code'        => (string)($order['ao_dai_code'] ?? ('#' . $gid)),
                    'name'        => (string)($order['ao_dai_name'] ?? 'Unknown Design'),
                    'rentals'     => 0,
                    'net_revenue' => 0.0,
                ];
            }
            $garmentMap[$gid]['rentals']++;
            $garmentMap[$gid]['net_revenue'] += $orderNet;

            $cid = (int)$order['customer_id'];
            if (!isset($customerMap[$cid])) {
                $customerMap[$cid] = [
                    'id'          => $cid,
                    'name'        => (string)($order['customer_name'] ?? ('Customer #' . $cid)),
                    'phone'       => (string)($order['customer_phone'] ?? ''),
                    'orders'      => 0,
                    'total_spend' => 0.0,
                ];
            }
            $customerMap[$cid]['orders']++;
            $customerMap[$cid]['total_spend'] += $orderNet;
        }

        // 2. Deposit currently held (open refundable cash liability across all non-cancelled orders)
        $depStmt = $this->pdo->query("
            SELECT COALESCE(SUM(CASE WHEN deposit_held > deposit_refunded THEN deposit_held - deposit_refunded ELSE 0 END), 0)
            FROM rental_orders
            WHERE status != 'cancelled'
        ");
        $depositCurrentlyHeld = $depStmt ? (float)$depStmt->fetchColumn() : 0.0;

        // 3. Overdue picked-up orders as of today
        $overdueStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM rental_orders
            WHERE status = 'picked_up' AND return_date < :today
        ");
        $overdueStmt->execute([':today' => $todayDate]);
        $overdueOrders = (int)$overdueStmt->fetchColumn();

        $topGarments = array_values($garmentMap);
        usort($topGarments, static fn(array $a, array $b): int => $b['net_revenue'] <=> $a['net_revenue']);
        $topGarments = array_slice($topGarments, 0, 10);

        $topCustomers = array_values($customerMap);
        usort($topCustomers, static fn(array $a, array $b): int => $b['total_spend'] <=> $a['total_spend']);
        $topCustomers = array_slice($topCustomers, 0, 10);

        return [
            'from' => $from,
            'to'   => $to,
            'kpis' => [
                'net_revenue'             => round($netRevenue, 2),
                'gross_rental_fee'        => round($grossRental, 2),
                'total_discount'          => round($totalDiscount, 2),
                'total_extra_fees'        => round($totalExtraFees, 2),
                'deposit_held_period'     => round($depositHeldPeriod, 2),
                'deposit_refunded_period' => round($depositRefundedPeriod, 2),
                'deposit_currently_held'  => round($depositCurrentlyHeld, 2),
                'total_orders'            => $totalOrders,
                'cancelled_orders'        => $cancelledOrders,
                'overdue_orders'          => $overdueOrders,
            ],
            'daily'         => array_values($dailyMap),
            'top_garments'  => $topGarments,
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * Fetch detailed ledger rows for Excel (.xlsx) export within [from, to].
     *
     * @return list<array<int, string|float|int>>
     */
    public function getExportRows(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT o.order_code, o.rent_date, o.return_date, o.actual_return_date, o.status,
                   c.name AS customer_name, c.phone AS customer_phone,
                   a.code AS ao_dai_code, a.name AS ao_dai_name, o.size,
                   o.rental_fee, o.discount, o.late_fee, o.damage_fee,
                   o.deposit_held, o.deposit_refunded
            FROM rental_orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN ao_dais a ON a.id = o.ao_dai_id
            WHERE o.rent_date >= :from_date AND o.rent_date <= :to_date
            ORDER BY o.rent_date ASC, o.id ASC
        ");
        $stmt->execute([':from_date' => $from, ':to_date' => $to]);
        $rows = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $status = (string)$r['status'];
            $net = $status === 'cancelled'
                ? 0.0
                : max(0.0, (float)$r['rental_fee'] - (float)$r['discount']) + (float)$r['late_fee'] + (float)$r['damage_fee'];
            $depBal = $status === 'cancelled'
                ? 0.0
                : max(0.0, (float)$r['deposit_held'] - (float)$r['deposit_refunded']);

            $rows[] = [
                (string)$r['order_code'],
                (string)$r['rent_date'],
                (string)$r['return_date'],
                (string)($r['actual_return_date'] ?? ''),
                $status,
                (string)($r['customer_name'] ?? ''),
                (string)($r['customer_phone'] ?? ''),
                (string)($r['ao_dai_code'] ?? ''),
                (string)($r['ao_dai_name'] ?? ''),
                (string)($r['size'] ?? ''),
                (float)$r['rental_fee'],
                (float)$r['discount'],
                (float)$r['late_fee'],
                (float)$r['damage_fee'],
                round($net, 2),
                (float)$r['deposit_held'],
                (float)$r['deposit_refunded'],
                round($depBal, 2),
            ];
        }

        return $rows;
    }
}
