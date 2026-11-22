<?php

declare(strict_types=1);

namespace App\Modules\RetailExtension\Services;

use PDO;

/**
 * Unified Retail & Rental Accounting Revenue Report Service.
 *
 * Accounting invariants:
 * - Net Revenue = SUM(MAX(0, subtotal - discount) + extra_fees) for non-cancelled orders.
 * - Split cleanly into rental_revenue vs sale_revenue.
 * - Refundable deposits (deposit_held - deposit_refunded) are strictly isolated as Liability.
 */
final class RevenueReportService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   from: string,
     *   to: string,
     *   kpis: array{
     *     net_revenue: float,
     *     rental_revenue: float,
     *     sale_revenue: float,
     *     gross_subtotal: float,
     *     total_discount: float,
     *     total_extra_fees: float,
     *     deposit_held_period: float,
     *     deposit_refunded_period: float,
     *     deposit_currently_held: float,
     *     total_orders: int,
     *     cancelled_orders: int,
     *     overdue_rentals: int
     *   },
     *   daily: list<array{date: string, orders: int, net_revenue: float, deposit_held: float}>,
     *   top_items: list<array{id: int, sku: string, name: string, orders: int, net_revenue: float}>,
     *   top_customers: list<array{id: int, name: string, phone: string, orders: int, total_spend: float}>
     * }
     */
    public function buildReport(string $from, string $to, ?string $today = null): array
    {
        $todayDate = $today ?? date('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT o.*, it.sku AS item_sku, it.name AS item_name,
                   c.name AS customer_name, c.phone AS customer_phone
            FROM retail_orders o
            LEFT JOIN retail_items it ON it.id = o.item_id
            LEFT JOIN retail_customers c ON c.id = o.customer_id
            WHERE o.start_date >= :from_date AND o.start_date <= :to_date
            ORDER BY o.start_date ASC, o.id ASC
        ");
        $stmt->execute([':from_date' => $from, ':to_date' => $to]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $netRevenue = 0.0;
        $rentalRevenue = 0.0;
        $saleRevenue = 0.0;
        $grossSubtotal = 0.0;
        $totalDiscount = 0.0;
        $totalExtraFees = 0.0;
        $depositHeldPeriod = 0.0;
        $depositRefundedPeriod = 0.0;
        $totalOrders = 0;
        $cancelledOrders = 0;

        $dailyMap = [];
        $itemMap = [];
        $customerMap = [];

        foreach ($orders as $order) {
            $status = (string)($order['status'] ?? 'confirmed');
            if ($status === 'cancelled') {
                $cancelledOrders++;
                continue;
            }

            $totalOrders++;
            $subtotal = (float)($order['subtotal'] ?? 0);
            $discount = (float)($order['discount'] ?? 0);
            $extraFees = (float)($order['extra_fees'] ?? 0);
            $depHeld = (float)($order['deposit_held'] ?? 0);
            $depRef = (float)($order['deposit_refunded'] ?? 0);

            $orderNet = max(0.0, $subtotal - $discount) + $extraFees;
            $netRevenue += $orderNet;
            if ((string)($order['order_type'] ?? 'rental') === 'sale') {
                $saleRevenue += $orderNet;
            } else {
                $rentalRevenue += $orderNet;
            }

            $grossSubtotal += $subtotal;
            $totalDiscount += $discount;
            $totalExtraFees += $extraFees;
            $depositHeldPeriod += $depHeld;
            $depositRefundedPeriod += $depRef;

            $day = substr((string)$order['start_date'], 0, 10);
            if (!isset($dailyMap[$day])) {
                $dailyMap[$day] = ['date' => $day, 'orders' => 0, 'net_revenue' => 0.0, 'deposit_held' => 0.0];
            }
            $dailyMap[$day]['orders']++;
            $dailyMap[$day]['net_revenue'] += $orderNet;
            $dailyMap[$day]['deposit_held'] += $depHeld;

            $iid = (int)$order['item_id'];
            if (!isset($itemMap[$iid])) {
                $itemMap[$iid] = [
                    'id'          => $iid,
                    'sku'         => (string)($order['item_sku'] ?? ('#' . $iid)),
                    'name'        => (string)($order['item_name'] ?? 'Unknown Item'),
                    'orders'      => 0,
                    'net_revenue' => 0.0,
                ];
            }
            $itemMap[$iid]['orders']++;
            $itemMap[$iid]['net_revenue'] += $orderNet;

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

        $depStmt = $this->pdo->query("
            SELECT COALESCE(SUM(CASE WHEN deposit_held > deposit_refunded THEN deposit_held - deposit_refunded ELSE 0 END), 0)
            FROM retail_orders
            WHERE status != 'cancelled'
        ");
        $depositCurrentlyHeld = $depStmt ? (float)$depStmt->fetchColumn() : 0.0;

        $overdueStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM retail_orders
            WHERE order_type = 'rental' AND status = 'active' AND end_date IS NOT NULL AND end_date < :today
        ");
        $overdueStmt->execute([':today' => $todayDate]);
        $overdueRentals = (int)$overdueStmt->fetchColumn();

        $topItems = array_values($itemMap);
        usort($topItems, static fn(array $a, array $b): int => $b['net_revenue'] <=> $a['net_revenue']);
        $topItems = array_slice($topItems, 0, 10);

        $topCustomers = array_values($customerMap);
        usort($topCustomers, static fn(array $a, array $b): int => $b['total_spend'] <=> $a['total_spend']);
        $topCustomers = array_slice($topCustomers, 0, 10);

        return [
            'from' => $from,
            'to'   => $to,
            'kpis' => [
                'net_revenue'             => round($netRevenue, 2),
                'rental_revenue'          => round($rentalRevenue, 2),
                'sale_revenue'            => round($saleRevenue, 2),
                'gross_subtotal'          => round($grossSubtotal, 2),
                'total_discount'          => round($totalDiscount, 2),
                'total_extra_fees'        => round($totalExtraFees, 2),
                'deposit_held_period'     => round($depositHeldPeriod, 2),
                'deposit_refunded_period' => round($depositRefundedPeriod, 2),
                'deposit_currently_held'  => round($depositCurrentlyHeld, 2),
                'total_orders'            => $totalOrders,
                'cancelled_orders'        => $cancelledOrders,
                'overdue_rentals'         => $overdueRentals,
            ],
            'daily'         => array_values($dailyMap),
            'top_items'     => $topItems,
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * @return list<array<int, string|float|int>>
     */
    public function getExportRows(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT o.order_code, o.order_type, o.start_date, o.end_date, o.actual_return_date, o.status,
                   c.name AS customer_name, c.phone AS customer_phone,
                   it.sku AS item_sku, it.name AS item_name, o.variant,
                   o.subtotal, o.discount, o.extra_fees, o.deposit_held, o.deposit_refunded
            FROM retail_orders o
            LEFT JOIN retail_customers c ON c.id = o.customer_id
            LEFT JOIN retail_items it ON it.id = o.item_id
            WHERE o.start_date >= :from_date AND o.start_date <= :to_date
            ORDER BY o.start_date ASC, o.id ASC
        ");
        $stmt->execute([':from_date' => $from, ':to_date' => $to]);
        $rows = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $status = (string)$r['status'];
            $net = $status === 'cancelled'
                ? 0.0
                : max(0.0, (float)$r['subtotal'] - (float)$r['discount']) + (float)$r['extra_fees'];
            $depBal = $status === 'cancelled'
                ? 0.0
                : max(0.0, (float)$r['deposit_held'] - (float)$r['deposit_refunded']);

            $rows[] = [
                (string)$r['order_code'],
                (string)$r['order_type'],
                (string)$r['start_date'],
                (string)($r['end_date'] ?? ''),
                (string)($r['actual_return_date'] ?? ''),
                $status,
                (string)($r['customer_name'] ?? ''),
                (string)($r['customer_phone'] ?? ''),
                (string)($r['item_sku'] ?? ''),
                (string)($r['item_name'] ?? ''),
                (string)($r['variant'] ?? ''),
                (float)$r['subtotal'],
                (float)$r['discount'],
                (float)$r['extra_fees'],
                round($net, 2),
                (float)$r['deposit_held'],
                (float)$r['deposit_refunded'],
                round($depBal, 2),
            ];
        }

        return $rows;
    }
}
