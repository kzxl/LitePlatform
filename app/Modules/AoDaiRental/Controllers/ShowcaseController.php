<?php

declare(strict_types=1);

namespace App\Modules\AoDaiRental\Controllers;

use DI\Container;
use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Mail\Mailer;
use Lite\Framework\Mail\TelegramNotifier;
use Lite\Framework\Security\HoneypotGuard;
use Lite\Framework\Seo\SchemaBuilder;
use Lite\Framework\Seo\SeoMeta;
use LiteView\ViewEngine;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public Ao Dai Rental Showcase & Fast Booking Inquiry Controller.
 */
final class ShowcaseController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->renderCatalog($request, $response);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = trim((string)($args['slug'] ?? ''));
        return $this->renderDetail($request, $response, $slug);
    }

    public function submitInquiry(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $returnSlug = trim((string)($body['return_slug'] ?? ''));

        // 1. Zero-CAPTCHA bot verification via HoneypotGuard
        if ($this->container->has(HoneypotGuard::class)) {
            /** @var HoneypotGuard $hp */
            $hp = $this->container->get(HoneypotGuard::class);
            if (!$hp->verify($body)) {
                return $this->respondInquiry(
                    $request,
                    $response->withStatus(422),
                    $returnSlug,
                    error: 'Yêu cầu chưa hợp lệ. Vui lòng chờ vài giây và thử lại.',
                    old: $body
                );
            }
        }

        // 2. Validate required contact info
        $name = trim((string)($body['name'] ?? ''));
        $phone = preg_replace('/[^0-9+\-\s]/', '', trim((string)($body['phone'] ?? ''))) ?? '';
        $zalo = trim((string)($body['zalo'] ?? '')) ?: $phone;
        $wearDate = trim((string)($body['wear_date'] ?? ''));
        $selectedCodes = trim((string)($body['selected_codes'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));

        if ($name === '' || strlen(preg_replace('/\D/', '', $phone) ?? '') < 8) {
            return $this->respondInquiry(
                $request,
                $response->withStatus(422),
                $returnSlug,
                error: 'Vui lòng nhập họ tên và số điện thoại hợp lệ (tối thiểu 8 chữ số).',
                old: $body
            );
        }

        $now = date('Y-m-d H:i:s');
        $clientIp = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1'));

        $summaryParts = [];
        if ($selectedCodes !== '') {
            $summaryParts[] = 'Mẫu đã chọn: ' . $selectedCodes;
        }
        if ($wearDate !== '') {
            $summaryParts[] = 'Ngày dự kiến mặc: ' . $wearDate;
        }
        if ($notes !== '') {
            $summaryParts[] = 'Ghi chú: ' . $notes;
        }
        $summaryMessage = implode(' | ', $summaryParts) ?: 'Khách hàng yêu cầu tư vấn thuê áo dài.';

        if ($this->container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);

            // 3. Upsert customer into CRM table (cross-engine safe across SQLite/MySQL/PgSQL)
            $find = $pdo->prepare('SELECT id FROM customers WHERE phone = :phone LIMIT 1');
            $find->execute([':phone' => $phone]);
            $existingId = $find->fetchColumn();

            if ($existingId !== false) {
                $upd = $pdo->prepare(
                    'UPDATE customers SET name = :name, zalo = COALESCE(NULLIF(:zalo, \'\'), zalo), updated_at = :updated_at WHERE id = :id'
                );
                $upd->execute([
                    ':name'       => mb_substr($name, 0, 150),
                    ':zalo'       => mb_substr($zalo, 0, 80),
                    ':updated_at' => $now,
                    ':id'         => (int)$existingId,
                ]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO customers (name, phone, zalo, notes, created_at) VALUES (:name, :phone, :zalo, :notes, :created_at)'
                );
                $ins->execute([
                    ':name'       => mb_substr($name, 0, 150),
                    ':phone'      => mb_substr($phone, 0, 40),
                    ':zalo'       => mb_substr($zalo, 0, 80),
                    ':notes'      => mb_substr($summaryMessage, 0, 500),
                    ':created_at' => $now,
                ]);
            }

            // 4. Persist lead in contact_leads for unified lead inbox
            $leadStmt = $pdo->prepare(
                'INSERT INTO contact_leads (name, email, phone, message, ip_address, created_at)
                 VALUES (:name, :email, :phone, :message, :ip, :created_at)'
            );
            $leadStmt->execute([
                ':name'       => mb_substr($name, 0, 120),
                ':email'      => 'booking@aodai.local',
                ':phone'      => mb_substr($phone, 0, 40),
                ':message'    => $summaryMessage,
                ':ip'         => mb_substr($clientIp, 0, 64),
                ':created_at' => $now,
            ]);
        }

        // 5. Notify shop owner via Mailer & Telegram
        $config = (array)$this->container->get('config');
        $notifyTo = (string)($config['mail']['notify_to'] ?? $config['mail']['from_address'] ?? 'admin@liteplatform.io');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($this->container->has(Mailer::class)) {
            /** @var Mailer $mailer */
            $mailer = $this->container->get(Mailer::class);
            $html = sprintf(
                '<h3>Yêu cầu đặt thử / thuê Áo Dài mới</h3><p><strong>Khách:</strong> %s</p><p><strong>SĐT / Zalo:</strong> %s</p><p><strong>Chi tiết:</strong> %s</p>',
                $e($name),
                $e($phone),
                $e($summaryMessage)
            );
            try {
                $mailer->send($notifyTo, "[Áo Dài] Khách mới: {$name} ({$phone})", $html);
            } catch (\Throwable) {
            }
        }

        if ($this->container->has(TelegramNotifier::class)) {
            /** @var TelegramNotifier $tg */
            $tg = $this->container->get(TelegramNotifier::class);
            if ($tg->isConfigured()) {
                $tg->send("<b>Đặt lịch thuê Áo Dài mới</b>\nKhách: {$e($name)}\nSĐT: {$e($phone)}\n{$e($summaryMessage)}");
            }
        }

        return $this->respondInquiry(
            $request,
            $response,
            $returnSlug,
            success: 'Đã gửi yêu cầu thành công! Tiệm sẽ liên hệ xác nhận lịch thử và giữ mẫu cho bạn trong ít phút.'
        );
    }

    /**
     * @param array<string, mixed> $old
     */
    private function respondInquiry(
        Request $request,
        Response $response,
        string $returnSlug,
        ?string $error = null,
        ?string $success = null,
        array $old = []
    ): Response {
        if ($returnSlug !== '') {
            return $this->renderDetail($request, $response, $returnSlug, $error, $success, $old);
        }

        return $this->renderCatalog($request, $response, $error, $success, $old);
    }

    /**
     * @param array<string, mixed> $old
     */
    private function renderCatalog(
        Request $request,
        Response $response,
        ?string $error = null,
        ?string $success = null,
        array $old = []
    ): Response {
        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);
        $query = $request->getQueryParams();

        $q = trim((string)($query['q'] ?? ''));
        $color = trim((string)($query['color'] ?? ''));
        $categoryId = (int)($query['category'] ?? 0);
        $maxPrice = (float)($query['max_price'] ?? 0);
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = 12;

        $items = [];
        $total = 0;
        $colors = [];
        $categories = [];

        if ($this->container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);

            $where = ["status = 'available'"];
            $params = [];

            if ($q !== '') {
                $where[] = '(name LIKE :q OR code LIKE :q2 OR material LIKE :q3)';
                $like = '%' . $q . '%';
                $params[':q'] = $like;
                $params[':q2'] = $like;
                $params[':q3'] = $like;
            }
            if ($color !== '') {
                $where[] = 'color = :color';
                $params[':color'] = $color;
            }
            if ($categoryId > 0) {
                $where[] = 'category_id = :category_id';
                $params[':category_id'] = $categoryId;
            }
            if ($maxPrice > 0) {
                $where[] = 'rental_price <= :max_price';
                $params[':max_price'] = $maxPrice;
            }

            $whereSql = implode(' AND ', $where);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ao_dais WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $listStmt = $pdo->prepare("
                SELECT id, code, name, slug, rental_price, deposit_default, color, sizes, material, featured_image
                FROM ao_dais
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $listStmt->execute($params);
            $items = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $colorRows = $pdo->query("SELECT DISTINCT color FROM ao_dais WHERE status = 'available' AND color IS NOT NULL AND color != '' ORDER BY color ASC");
            $colors = $colorRows ? ($colorRows->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

            try {
                $catRows = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC');
                $categories = $catRows ? ($catRows->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (\Throwable) {
                $categories = [];
            }
        }

        $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
        $seo = $this->container->has(SeoMeta::class) ? $this->container->get(SeoMeta::class) : new SeoMeta();
        $seo = $seo
            ->withTitle('Bộ Sưu Tập Mẫu Áo Dài Cho Thuê')
            ->withDescription('Tham khảo các mẫu áo dài cưới hỏi, chụp ảnh, dự tiệc mới nhất. Chọn nhiều mẫu yêu thích và liên hệ đặt lịch thử nhanh.')
            ->withCanonical($appUrl . '/mau-ao-dai')
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Trang chủ', 'url' => $appUrl . '/'],
                ['name' => 'Mẫu Áo Dài', 'url' => $appUrl . '/mau-ao-dai'],
            ]));

        return $view->renderResponse($response, 'aodai::index', [
            'seo'         => $seo,
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => max(1, (int)ceil($total / $perPage)),
            'colors'      => $colors,
            'categories'  => $categories,
            'filters'     => ['q' => $q, 'color' => $color, 'category' => $categoryId, 'max_price' => $maxPrice > 0 ? $maxPrice : ''],
            'zaloPhone'   => $this->resolveZaloPhone(),
            'inquiryErr'  => $error,
            'inquiryOk'   => $success,
            'old'         => $old,
        ]);
    }

    /**
     * @param array<string, mixed> $old
     */
    private function renderDetail(
        Request $request,
        Response $response,
        string $slug,
        ?string $error = null,
        ?string $success = null,
        array $old = []
    ): Response {
        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);
        $item = null;

        if ($slug !== '' && $this->container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);
            $stmt = $pdo->prepare("SELECT * FROM ao_dais WHERE slug = :slug AND status != 'hidden' LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $item = $row;
            }
        }

        if ($item === null) {
            return $view->renderResponse($response->withStatus(404), 'errors.404', [
                'title' => 'Không tìm thấy mẫu áo dài',
            ]);
        }

        $gallery = [];
        if (!empty($item['featured_image'])) {
            $gallery[] = (string)$item['featured_image'];
        }
        $decoded = json_decode((string)($item['gallery_json'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach ($decoded as $url) {
                if (is_string($url) && $url !== '' && !in_array($url, $gallery, true)) {
                    $gallery[] = $url;
                }
            }
        }

        $appUrl = rtrim((string)($this->container->get('config')['url'] ?? 'http://localhost:8000'), '/');
        $canonical = $appUrl . '/mau-ao-dai/' . rawurlencode((string)$item['slug']);
        $metaTitle = trim((string)($item['meta_title'] ?? '')) ?: ((string)$item['name'] . ' (' . (string)$item['code'] . ')');
        $metaDesc = trim((string)($item['meta_description'] ?? '')) ?: ('Thuê mẫu ' . (string)$item['name'] . ' giá ' . number_format((float)$item['rental_price']) . 'đ.');

        $seo = $this->container->has(SeoMeta::class) ? $this->container->get(SeoMeta::class) : new SeoMeta();
        $seo = $seo
            ->withTitle($metaTitle)
            ->withDescription($metaDesc)
            ->withCanonical($canonical)
            ->addSchema(SchemaBuilder::product(
                name: (string)$item['name'],
                url: $canonical,
                price: (float)$item['rental_price'],
                currency: 'VND',
                inStock: (string)$item['status'] === 'available',
                image: $gallery[0] ?? null,
                description: $metaDesc,
                sku: (string)$item['code']
            ))
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Trang chủ', 'url' => $appUrl . '/'],
                ['name' => 'Mẫu Áo Dài', 'url' => $appUrl . '/mau-ao-dai'],
                ['name' => (string)$item['name'], 'url' => $canonical],
            ]));

        return $view->renderResponse($response, 'aodai::show', [
            'seo'        => $seo,
            'item'       => $item,
            'gallery'    => $gallery,
            'zaloPhone'  => $this->resolveZaloPhone(),
            'inquiryErr' => $error,
            'inquiryOk'  => $success,
            'old'        => $old,
        ]);
    }

    private function resolveZaloPhone(): string
    {
        if ($this->container->has(SiteSettings::class)) {
            /** @var SiteSettings $settings */
            $settings = $this->container->get(SiteSettings::class);
            $raw = $settings->get('hotline', '');
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if ($digits !== '') {
                return $digits;
            }
        }

        return '';
    }
}
