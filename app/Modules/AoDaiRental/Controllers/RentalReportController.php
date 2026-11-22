<?php

declare(strict_types=1);

namespace App\Modules\AoDaiRental\Controllers;

use App\Modules\AoDaiRental\Services\RevenueReportService;
use DI\Container;
use LiteAdmin\AdminDashboard;
use LiteExport\Exporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin Revenue & Refundable Deposit Accounting Report Controller + Excel (.xlsx) Export.
 */
final class RentalReportController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        /** @var RevenueReportService $service */
        $service = $this->container->get(RevenueReportService::class);

        [$from, $to] = $this->resolveDateRange($request);
        $report = $service->buildReport($from, $to);
        $kpis = $report['kpis'];

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = static fn(float $v): string => number_format($v, 0, ',', '.') . ' ₫';

        $exportUrl = '/admin/aodai-reports/export?from=' . rawurlencode($from) . '&to=' . rawurlencode($to);

        // SVG Bar Chart for daily net revenue (zero JS chart dependency)
        $chartHtml = $this->renderRevenueChart($report['daily']);

        // Top garments table rows
        $garmentRows = '';
        if (empty($report['top_garments'])) {
            $garmentRows = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:1.25rem;">No rental orders in this period.</td></tr>';
        } else {
            foreach ($report['top_garments'] as $g) {
                $garmentRows .= '<tr>'
                    . '<td><code>' . $e((string)$g['code']) . '</code></td>'
                    . '<td>' . $e((string)$g['name']) . '</td>'
                    . '<td style="text-align:right;font-weight:600;">' . (int)$g['rentals'] . '</td>'
                    . '<td style="text-align:right;font-weight:600;">' . $fmt((float)$g['net_revenue']) . '</td>'
                    . '</tr>';
            }
        }

        // Top customers table rows
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

        $overdueColor = $kpis['overdue_orders'] > 0 ? 'var(--danger)' : 'var(--success)';

        $content = <<<HTML
<div class="page-header" style="flex-wrap:wrap;gap:1rem;">
    <div>
        <h1>Ao Dai Revenue &amp; Deposit Report</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">Net Revenue = (Rental Fee - Discount) + Late/Damage Fees. Refundable deposits are separated as liability.</p>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        <form method="GET" action="/admin/aodai-reports" style="display:flex;gap:0.5rem;align-items:center;">
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
        <div class="metric-header"><span class="metric-label">Net Rental Revenue</span></div>
        <div class="metric-value" style="color:var(--primary);">{$fmt($kpis['net_revenue'])}</div>
        <div class="metric-desc">Gross: {$fmt($kpis['gross_rental_fee'])} · Disc: -{$fmt($kpis['total_discount'])} · Extra: +{$fmt($kpis['total_extra_fees'])}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Refundable Deposits Held</span></div>
        <div class="metric-value">{$fmt($kpis['deposit_currently_held'])}</div>
        <div class="metric-desc">Period collected: {$fmt($kpis['deposit_held_period'])} · Refunded: {$fmt($kpis['deposit_refunded_period'])}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Valid Rental Orders</span></div>
        <div class="metric-value">{$kpis['total_orders']}</div>
        <div class="metric-desc">Cancelled excluded: {$kpis['cancelled_orders']}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Overdue Returns</span></div>
        <div class="metric-value" style="color:{$overdueColor};">{$kpis['overdue_orders']}</div>
        <div class="metric-desc">Picked-up orders past expected return date</div>
    </div>
</div>

<div class="card">
    <div class="section-card-title">Daily Net Revenue ({$e($from)} &rarr; {$e($to)})</div>
    {$chartHtml}
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:1.25rem;">
    <div class="card">
        <div class="section-card-title">Top Rented Ao Dai Designs</div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th>Code</th><th>Design</th><th style="text-align:right;">Rentals</th><th style="text-align:right;">Net Revenue</th></tr>
                </thead>
                <tbody>{$garmentRows}</tbody>
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

        $html = $admin->getHtmlRenderer()->layout('Ao Dai Revenue Report', $content, 'aodai-reports');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function export(Request $request, Response $response): Response
    {
        /** @var RevenueReportService $service */
        $service = $this->container->get(RevenueReportService::class);
        [$from, $to] = $this->resolveDateRange($request);

        $headers = [
            'Order Code', 'Rent Date', 'Return Date', 'Actual Return', 'Status',
            'Customer', 'Phone', 'Design Code', 'Design Name', 'Size',
            'Rental Fee', 'Discount', 'Late Fee', 'Damage Fee', 'Net Revenue',
            'Deposit Held', 'Deposit Refunded', 'Deposit Balance',
        ];
        $rows = $service->getExportRows($from, $to);
        $xlsxBinary = (string)Exporter::xlsx($rows, headers: $headers);
        $filename = sprintf('aodai_revenue_%s_to_%s.xlsx', $from, $to);

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
