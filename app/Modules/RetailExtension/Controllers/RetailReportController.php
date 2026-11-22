<?php

declare(strict_types=1);

namespace App\Modules\RetailExtension\Controllers;

use App\Modules\RetailExtension\Services\RevenueReportService;
use Lite\Framework\Core\Plugin\PluginContext;
use LiteAdmin\AdminDashboard;
use LiteExport\Exporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * RBAC-protected Admin Revenue & Refundable Deposits Report Controller + Excel (.xlsx) Export.
 */
final class RetailReportController
{
    public function __construct(private readonly PluginContext $context)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->context->getService(AdminDashboard::class);
        /** @var RevenueReportService $service */
        $service = $this->context->getService(RevenueReportService::class);

        [$from, $to] = $this->resolveDateRange($request);
        $report = $service->buildReport($from, $to);
        $kpis = $report['kpis'];

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = static fn(float $v): string => number_format($v, 0, ',', '.') . ' ₫';
        $exportUrl = '/admin/retail-reports/export?from=' . rawurlencode($from) . '&to=' . rawurlencode($to);

        $chartHtml = $this->renderRevenueChart($report['daily']);

        $itemRows = '';
        if (empty($report['top_items'])) {
            $itemRows = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:1.25rem;">No orders in this period.</td></tr>';
        } else {
            foreach ($report['top_items'] as $it) {
                $itemRows .= '<tr>'
                    . '<td><code>' . $e((string)$it['sku']) . '</code></td>'
                    . '<td>' . $e((string)$it['name']) . '</td>'
                    . '<td style="text-align:right;font-weight:600;">' . (int)$it['orders'] . '</td>'
                    . '<td style="text-align:right;font-weight:600;">' . $fmt((float)$it['net_revenue']) . '</td>'
                    . '</tr>';
            }
        }

        $customerRows = '';
        if (empty($report['top_customers'])) {
            $customerRows = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:1.25rem;">No customer orders in this period.</td></tr>';
        } else {
            foreach ($report['top_customers'] as $c) {
                $customerRows .= '<tr>'
                    . '<td>' . $e((string)$c['name']) . '</td>'
                    . '<td>' . $e((string)$c['phone']) . '</td>'
                    . '<td style="text-align:right;font-weight:600;">' . (int)$c['orders'] . '</td>'
                    . '<td style="text-align:right;font-weight:600;">' . $fmt((float)$c['total_spend']) . '</td>'
                    . '</tr>';
            }
        }

        $overdueColor = $kpis['overdue_rentals'] > 0 ? 'var(--danger)' : 'var(--success)';

        $content = <<<HTML
<div class="page-header" style="flex-wrap:wrap;gap:1rem;">
    <div>
        <h1>Retail &amp; Rental Revenue Report</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">Net Revenue = (Subtotal - Discount) + Extra Fees (Rental &amp; Sale). Refundable deposits are separated as liability.</p>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        <form method="GET" action="/admin/retail-reports" style="display:flex;gap:0.5rem;align-items:center;">
            <input type="date" name="from" value="{$e($from)}" class="form-control" style="width:auto;">
            <span style="color:var(--text-muted);font-size:0.85rem;">to</span>
            <input type="date" name="to" value="{$e($to)}" class="form-control" style="width:auto;">
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
        <a href="{$exportUrl}" class="btn btn-outline">Export Excel (.xlsx)</a>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Net Revenue</span></div>
        <div class="metric-value" style="color:var(--primary);">{$fmt($kpis['net_revenue'])}</div>
        <div class="metric-desc">Rental: {$fmt($kpis['rental_revenue'])} · Sale: {$fmt($kpis['sale_revenue'])}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Refundable Deposits Held</span></div>
        <div class="metric-value">{$fmt($kpis['deposit_currently_held'])}</div>
        <div class="metric-desc">Period collected: {$fmt($kpis['deposit_held_period'])} · Refunded: {$fmt($kpis['deposit_refunded_period'])}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Valid Orders</span></div>
        <div class="metric-value">{$kpis['total_orders']}</div>
        <div class="metric-desc">Cancelled excluded: {$kpis['cancelled_orders']}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Overdue Rentals</span></div>
        <div class="metric-value" style="color:{$overdueColor};">{$kpis['overdue_rentals']}</div>
        <div class="metric-desc">Active rentals past expected return date</div>
    </div>
</div>

<div class="card">
    <div class="section-card-title">Daily Net Revenue ({$e($from)} &rarr; {$e($to)})</div>
    {$chartHtml}
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:1.25rem;">
    <div class="card">
        <div class="section-card-title">Top Performing Items</div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th>SKU</th><th>Item</th><th style="text-align:right;">Orders</th><th style="text-align:right;">Net Revenue</th></tr>
                </thead>
                <tbody>{$itemRows}</tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="section-card-title">Top Customers</div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th>Customer</th><th>Phone</th><th style="text-align:right;">Orders</th><th style="text-align:right;">Total Spend</th></tr>
                </thead>
                <tbody>{$customerRows}</tbody>
            </table>
        </div>
    </div>
</div>
HTML;

        $html = $admin->getHtmlRenderer()->layout('Retail & Rental Reports', $content, 'retail-reports');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function export(Request $request, Response $response): Response
    {
        /** @var RevenueReportService $service */
        $service = $this->context->getService(RevenueReportService::class);
        [$from, $to] = $this->resolveDateRange($request);

        $actor = (string)$request->getAttribute('admin_user', 'system');
        $this->context->auditEvent('retail.report_exported', 'INFO', $actor, "/admin/retail-reports/export?from={$from}&to={$to}");

        $headers = [
            'Order Code', 'Type', 'Start Date', 'Return Due', 'Actual Return', 'Status',
            'Customer', 'Phone', 'SKU', 'Item Name', 'Variant',
            'Subtotal', 'Discount', 'Extra Fees', 'Net Revenue',
            'Deposit Held', 'Deposit Refunded', 'Deposit Balance',
        ];
        $rows = $service->getExportRows($from, $to);
        $xlsxBinary = (string)Exporter::xlsx($rows, headers: $headers);
        $filename = sprintf('retail_revenue_%s_to_%s.xlsx', $from, $to);

        $response->getBody()->write($xlsxBinary);
        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $query = $request->getQueryParams();
        $defaultFrom = date('Y-m-01');
        $defaultTo = date('Y-m-t');

        $from = (string)($query['from'] ?? $defaultFrom);
        $to = (string)($query['to'] ?? $defaultTo);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $defaultFrom;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $defaultTo;
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @param list<array{date: string, orders: int, net_revenue: float, deposit_held: float}> $daily
     */
    private function renderRevenueChart(array $daily): string
    {
        if (empty($daily)) {
            return '<p style="color:var(--text-muted);font-size:0.875rem;padding:1rem 0;">No revenue data recorded for the selected date range.</p>';
        }

        $maxVal = max(1.0, max(array_map(static fn(array $d): float => (float)$d['net_revenue'], $daily)));
        $count = count($daily);
        $barWidth = max(18, min(48, (int)floor(720 / max(1, $count)) - 8));
        $gap = 10;
        $svgWidth = max(420, $count * ($barWidth + $gap) + 40);
        $svgHeight = 190;
        $plotHeight = 140;

        $bars = '';
        foreach ($daily as $i => $row) {
            $h = (int)round(((float)$row['net_revenue'] / $maxVal) * $plotHeight);
            $x = 20 + $i * ($barWidth + $gap);
            $y = 18 + ($plotHeight - $h);
            $label = htmlspecialchars(substr($row['date'], 5), ENT_QUOTES, 'UTF-8');
            $valFmt = htmlspecialchars(number_format((float)$row['net_revenue'], 0, ',', '.') . 'đ', ENT_QUOTES, 'UTF-8');

            $bars .= "<g><title>{$row['date']}: {$valFmt} ({$row['orders']} orders)</title>"
                . "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$barWidth}\" height=\"{$h}\" rx=\"4\" fill=\"var(--primary)\" opacity=\"0.9\"/>"
                . "<text x=\"" . ($x + (int)($barWidth / 2)) . "\" y=\"" . ($svgHeight - 8) . "\" text-anchor=\"middle\" font-size=\"10\" fill=\"var(--text-muted)\">{$label}</text>"
                . "</g>";
        }

        return "<div style=\"overflow-x:auto;\"><svg width=\"{$svgWidth}\" height=\"{$svgHeight}\" viewBox=\"0 0 {$svgWidth} {$svgHeight}\" role=\"img\" aria-label=\"Daily Net Revenue Chart\">{$bars}</svg></div>";
    }
}
