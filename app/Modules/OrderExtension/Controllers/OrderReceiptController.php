<?php

declare(strict_types=1);

namespace App\Modules\OrderExtension\Controllers;

use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Core\Plugin\PluginContext;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Clean printable A5 / K80 Order, Rental Contract & Deposit Settlement Receipt (`/admin/order/receipt/{id}`).
 */
final class OrderReceiptController
{
    public function __construct(private readonly PluginContext $context)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $pdo = $this->context->db();

        $stmt = $pdo->prepare("
            SELECT o.*, c.name AS customer_name, c.phone AS customer_phone, c.chat_handle,
                   it.sku AS item_sku, it.name AS item_name
            FROM retail_orders o
            LEFT JOIN retail_customers c ON c.id = o.customer_id
            LEFT JOIN retail_items it ON it.id = o.item_id
            WHERE o.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($order)) {
            $response->getBody()->write('<h1>Order not found</h1>');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        $settings = $this->context->hasService(SiteSettings::class) ? $this->context->getService(SiteSettings::class) : null;
        $shopName = $settings ? $settings->get('site_name', 'LitePlatform Store') : 'LitePlatform Store';
        $hotline = $settings ? $settings->get('hotline', '') : '';
        $address = $settings ? $settings->get('address', '') : '';

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = static fn(float $v): string => number_format($v, 0, ',', '.') . ' đ';

        $subtotal = (float)$order['subtotal'];
        $discount = (float)$order['discount'];
        $extra = (float)$order['extra_fees'];
        $netTotal = max(0.0, $subtotal - $discount) + $extra;
        $depHeld = (float)$order['deposit_held'];
        $depRef = (float)$order['deposit_refunded'];
        $depBalance = max(0.0, $depHeld - $depRef);

        $typeLabel = ((string)$order['order_type']) === 'sale' ? 'ĐƠN BÁN HÀNG' : 'PHIẾU THUÊ & BIÊN NHẬN CỌC';
        $datesRow = ((string)$order['order_type']) === 'rental'
            ? '<tr><td>Thời gian thuê</td><td style="text-align:right;font-weight:600;">' . $e((string)$order['start_date']) . ' &rarr; ' . $e((string)($order['end_date'] ?? '-')) . '</td></tr>'
            : '<tr><td>Ngày lập đơn</td><td style="text-align:right;font-weight:600;">' . $e((string)$order['start_date']) . '</td></tr>';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Phiếu {$e((string)$order['order_code'])} | {$e($shopName)}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0f172a; margin: 0; padding: 1.5rem; background: #f8fafc; }
        .sheet { max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1.75rem; }
        .head { text-align: center; border-bottom: 1px dashed #cbd5e1; padding-bottom: 1rem; margin-bottom: 1rem; }
        .head h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        .head p { font-size: 0.82rem; color: #475569; margin: 0.15rem 0; }
        .badge { display: inline-block; margin-top: 0.5rem; padding: 0.2rem 0.65rem; border: 1px solid #0f172a; border-radius: 4px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.04em; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-bottom: 1rem; }
        td { padding: 0.42rem 0; border-bottom: 1px solid #e2e8f0; }
        .total-row td { font-weight: 800; font-size: 1rem; border-top: 2px solid #0f172a; border-bottom: none; padding-top: 0.65rem; }
        .deposit-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem 1rem; font-size: 0.86rem; margin-bottom: 1.25rem; }
        .sigs { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; text-align: center; margin-top: 1.5rem; font-size: 0.84rem; }
        .no-print { text-align: center; margin-top: 1.25rem; }
        .btn { padding: 0.55rem 1.2rem; background: #0f62fe; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; font-size: 0.88rem; }
        @media print { body { background: #fff; padding: 0; } .sheet { border: none; padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="head">
        <h1>{$e($shopName)}</h1>
        <p>{$e($address)} {$e($hotline !== '' ? '· Hotline: ' . $hotline : '')}</p>
        <div class="badge">{$typeLabel}</div>
    </div>
    <table>
        <tr><td>Mã đơn</td><td style="text-align:right;font-weight:700;">{$e((string)$order['order_code'])}</td></tr>
        <tr><td>Khách hàng</td><td style="text-align:right;">{$e((string)($order['customer_name'] ?? ''))} ({$e((string)($order['customer_phone'] ?? ''))})</td></tr>
        <tr><td>Sản phẩm / Mẫu</td><td style="text-align:right;"><code>{$e((string)($order['item_sku'] ?? ''))}</code> — {$e((string)($order['item_name'] ?? ''))}</td></tr>
        {$datesRow}
        <tr><td>Trạng thái</td><td style="text-align:right;text-transform:uppercase;font-weight:600;">{$e((string)$order['status'])}</td></tr>
    </table>
    <table>
        <tr><td>Tiền thuê / Giá bán</td><td style="text-align:right;">{$fmt($subtotal)}</td></tr>
        <tr><td>Giảm giá</td><td style="text-align:right;">-{$fmt($discount)}</td></tr>
        <tr><td>Phụ phí (trễ hạn / hư hại / ship)</td><td style="text-align:right;">+{$fmt($extra)}</td></tr>
        <tr class="total-row"><td>DOANH THU / THANH TOÁN THUẦN</td><td style="text-align:right;">{$fmt($netTotal)}</td></tr>
    </table>
    <div class="deposit-box">
        <div><strong>Tiền cọc giữ đồ (hoàn lại khi trả đủ &amp; nguyên vẹn):</strong> {$fmt($depHeld)}</div>
        <div>Đã hoàn cọc: {$fmt($depRef)} &middot; <strong>Còn giữ cọc cần hoàn: {$fmt($depBalance)}</strong></div>
    </div>
    <div class="sigs">
        <div><strong>Khách hàng ký nhận</strong><br><br><br><em>(Ký &amp; ghi rõ họ tên)</em></div>
        <div><strong>Đại diện cửa hàng</strong><br><br><br><em>(Ký &amp; ghi rõ họ tên)</em></div>
    </div>
    <div class="no-print">
        <button type="button" class="btn" onclick="window.print()">In phiếu (A5 / K80)</button>
        <a href="/admin/order" style="margin-left:0.75rem;color:#475569;font-size:0.86rem;">&larr; Quay lại Đơn hàng</a>
    </div>
</div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
