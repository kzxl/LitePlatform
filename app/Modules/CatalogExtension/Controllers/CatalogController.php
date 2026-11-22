<?php

declare(strict_types=1);

namespace App\Modules\CatalogExtension\Controllers;

use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Core\Plugin\PluginContext;
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
 * Standalone Public Catalog & Inquiry Controller (zero hard dependency on CRM or Orders).
 * Emits `catalog.inquiry.submitted` on HookBus so CrmExtension / Notification plugins react cleanly.
 */
final class CatalogController
{
    public function __construct(private readonly PluginContext $context)
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
        return $this->renderDetail($request, $response, trim((string)($args['slug'] ?? '')));
    }

    public function submitInquiry(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $returnSlug = trim((string)($body['return_slug'] ?? ''));

        if ($this->context->hasService(HoneypotGuard::class)) {
            /** @var HoneypotGuard $hp */
            $hp = $this->context->getService(HoneypotGuard::class);
            if (!$hp->verify($body)) {
                return $this->respondInquiry($request, $response->withStatus(422), $returnSlug, error: 'Yêu cầu chưa hợp lệ. Vui lòng thử lại.', old: $body);
            }
        }

        $name = trim((string)($body['name'] ?? ''));
        $phone = preg_replace('/[^0-9+\-\s]/', '', trim((string)($body['phone'] ?? ''))) ?? '';
        $chatHandle = trim((string)($body['chat_handle'] ?? '')) ?: $phone;
        $targetDate = trim((string)($body['target_date'] ?? ''));
        $selectedSkus = trim((string)($body['selected_skus'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));

        if ($name === '' || strlen(preg_replace('/\D/', '', $phone) ?? '') < 8) {
            return $this->respondInquiry($request, $response->withStatus(422), $returnSlug, error: 'Vui lòng nhập họ tên và số điện thoại hợp lệ (tối thiểu 8 chữ số).', old: $body);
        }

        $now = date('Y-m-d H:i:s');
        $clientIp = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1'));

        $parts = [];
        if ($selectedSkus !== '') {
            $parts[] = 'Mã chọn: ' . $selectedSkus;
        }
        if ($targetDate !== '') {
            $parts[] = 'Ngày dự kiến: ' . $targetDate;
        }
        if ($notes !== '') {
            $parts[] = 'Ghi chú: ' . $notes;
        }
        $summaryMessage = implode(' | ', $parts) ?: 'Khách hàng yêu cầu tư vấn từ Catalog.';

        $pdo = $this->context->db();
        $pdo->prepare(
            'INSERT INTO contact_leads (name, email, phone, message, ip_address, created_at)
             VALUES (:name, :email, :phone, :message, :ip, :c)'
        )->execute([
            ':name'    => mb_substr($name, 0, 120),
            ':email'   => 'inquiry@catalog.local',
            ':phone'   => mb_substr($phone, 0, 40),
            ':message' => $summaryMessage,
            ':ip'      => mb_substr($clientIp, 0, 64),
            ':c'       => $now,
        ]);

        // Decoupled HookBus event: CrmExtension listens and auto-upserts customer!
        $this->context->hooks()->doAction('catalog.inquiry.submitted', [
            'name'          => $name,
            'phone'         => $phone,
            'chat_handle'   => $chatHandle,
            'selected_skus' => $selectedSkus,
            'target_date'   => $targetDate,
            'notes'         => $summaryMessage,
        ]);

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $notifyTo = $this->resolveNotifyEmail();
        if ($notifyTo !== '' && $this->context->hasService(Mailer::class)) {
            try {
                $this->context->getService(Mailer::class)->send(
                    $notifyTo,
                    "[Catalog] Yêu cầu mới: {$name} ({$phone})",
                    "<h3>Yêu cầu mới</h3><p><strong>Khách:</strong> {$e($name)} ({$e($phone)})</p><p>{$e($summaryMessage)}</p>"
                );
            } catch (\Throwable) {
            }
        }
        if ($this->context->hasService(TelegramNotifier::class)) {
            $tg = $this->context->getService(TelegramNotifier::class);
            if ($tg->isConfigured()) {
                $tg->send("<b>Yêu cầu mới từ Catalog</b>\nKhách: {$e($name)}\nSĐT: {$e($phone)}\n{$e($summaryMessage)}");
            }
        }

        return $this->respondInquiry($request, $response, $returnSlug, success: 'Đã gửi yêu cầu thành công! Cửa hàng sẽ liên hệ xác nhận trong ít phút.');
    }

    /**
     * @param array<string, mixed> $old
     */
    private function respondInquiry(Request $request, Response $response, string $returnSlug, ?string $error = null, ?string $success = null, array $old = []): Response
    {
        return $returnSlug !== ''
            ? $this->renderDetail($request, $response, $returnSlug, $error, $success, $old)
            : $this->renderCatalog($request, $response, $error, $success, $old);
    }

    /**
     * @param array<string, mixed> $old
     */
    private function renderCatalog(Request $request, Response $response, ?string $error = null, ?string $success = null, array $old = []): Response
    {
        /** @var ViewEngine $view */
        $view = $this->context->getService(ViewEngine::class);
        $query = $request->getQueryParams();

        $q = trim((string)($query['q'] ?? ''));
        $listingType = trim((string)($query['type'] ?? ''));
        $categoryId = (int)($query['category'] ?? 0);
        $maxPrice = (float)($query['max_price'] ?? 0);
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = 12;

        $pdo = $this->context->db();
        $where = ["status = 'active'"];
        $params = [];

        if ($q !== '') {
            $where[] = '(name LIKE :q OR sku LIKE :q2 OR variant_summary LIKE :q3)';
            $like = '%' . $q . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        if (in_array($listingType, ['rent', 'sale'], true)) {
            $where[] = "(listing_type = :ltype OR listing_type = 'both')";
            $params[':ltype'] = $listingType;
        }
        if ($categoryId > 0) {
            $where[] = 'category_id = :cat_id';
            $params[':cat_id'] = $categoryId;
        }
        if ($maxPrice > 0) {
            $where[] = 'price <= :max_price';
            $params[':max_price'] = $maxPrice;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM retail_items WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $listStmt = $pdo->prepare("SELECT id, sku, name, slug, listing_type, price, deposit_default, variant_summary, featured_image FROM retail_items WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
        $listStmt->execute($params);
        $items = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $categories = [];
        try {
            $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
        }

        $catalogTitle = $this->context->getSetting('catalog_title', 'Danh Mục Sản Phẩm & Cho Thuê');
        $baseUrl = $this->resolveBaseUrl($request);
        $seo = ($this->context->hasService(SeoMeta::class) ? $this->context->getService(SeoMeta::class) : new SeoMeta())
            ->withTitle($catalogTitle)
            ->withDescription('Tham khảo danh mục sản phẩm / cho thuê mới nhất. Chọn nhiều mẫu yêu thích và gửi liên hệ nhanh.')
            ->withCanonical($baseUrl . '/catalog')
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Trang chủ', 'url' => $baseUrl . '/'],
                ['name' => $catalogTitle, 'url' => $baseUrl . '/catalog'],
            ]));

        return $view->renderResponse($response, 'catalog::index', [
            'seo'          => $seo,
            'catalogTitle' => $catalogTitle,
            'items'        => $items,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int)ceil($total / $perPage)),
            'categories'   => $categories,
            'filters'      => ['q' => $q, 'type' => $listingType, 'category' => $categoryId, 'max_price' => $maxPrice > 0 ? $maxPrice : ''],
            'zaloPhone'    => $this->resolveZaloPhone(),
            'inquiryErr'   => $error,
            'inquiryOk'    => $success,
            'old'          => $old,
        ]);
    }

    /**
     * @param array<string, mixed> $old
     */
    private function renderDetail(Request $request, Response $response, string $slug, ?string $error = null, ?string $success = null, array $old = []): Response
    {
        /** @var ViewEngine $view */
        $view = $this->context->getService(ViewEngine::class);
        $pdo = $this->context->db();

        $item = null;
        if ($slug !== '') {
            $stmt = $pdo->prepare("SELECT * FROM retail_items WHERE slug = :slug AND status != 'hidden' LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $item = $row;
            }
        }
        if ($item === null) {
            return $view->renderResponse($response->withStatus(404), 'errors.404', ['title' => 'Không tìm thấy sản phẩm']);
        }

        $gallery = [];
        if (!empty($item['featured_image'])) {
            $gallery[] = (string)$item['featured_image'];
        }
        foreach ((array)(json_decode((string)($item['gallery_json'] ?? '[]'), true) ?: []) as $url) {
            if (is_string($url) && $url !== '' && !in_array($url, $gallery, true)) {
                $gallery[] = $url;
            }
        }

        $attributes = [];
        foreach ((array)(json_decode((string)($item['attributes_json'] ?? '[]'), true) ?: []) as $pair) {
            if (is_array($pair) && trim((string)($pair['key'] ?? '')) !== '') {
                $attributes[] = ['key' => trim((string)$pair['key']), 'value' => trim((string)($pair['value'] ?? ''))];
            }
        }

        $baseUrl = $this->resolveBaseUrl($request);
        $canonical = $baseUrl . '/catalog/' . rawurlencode((string)$item['slug']);
        $metaTitle = trim((string)($item['meta_title'] ?? '')) ?: ((string)$item['name'] . ' (' . (string)$item['sku'] . ')');
        $metaDesc = trim((string)($item['meta_description'] ?? '')) ?: ((string)$item['name'] . ' — ' . number_format((float)$item['price']) . 'đ');

        $seo = ($this->context->hasService(SeoMeta::class) ? $this->context->getService(SeoMeta::class) : new SeoMeta())
            ->withTitle($metaTitle)
            ->withDescription($metaDesc)
            ->withCanonical($canonical)
            ->addSchema(SchemaBuilder::product(
                name: (string)$item['name'],
                url: $canonical,
                price: (float)$item['price'],
                currency: 'VND',
                inStock: (string)$item['status'] === 'active',
                image: $gallery[0] ?? null,
                description: $metaDesc,
                sku: (string)$item['sku']
            ))
            ->addSchema(SchemaBuilder::breadcrumb([
                ['name' => 'Trang chủ', 'url' => $baseUrl . '/'],
                ['name' => 'Catalog', 'url' => $baseUrl . '/catalog'],
                ['name' => (string)$item['name'], 'url' => $canonical],
            ]));

        return $view->renderResponse($response, 'catalog::show', [
            'seo'        => $seo,
            'item'       => $item,
            'gallery'    => $gallery,
            'attributes' => $attributes,
            'zaloPhone'  => $this->resolveZaloPhone(),
            'inquiryErr' => $error,
            'inquiryOk'  => $success,
            'old'        => $old,
        ]);
    }

    private function resolveBaseUrl(Request $request): string
    {
        $uri = $request->getUri();
        return ($uri->getScheme() ?: 'http') . '://' . ($uri->getAuthority() ?: 'localhost:8000');
    }

    private function resolveZaloPhone(): string
    {
        if ($this->context->hasService(SiteSettings::class)) {
            $digits = preg_replace('/\D/', '', $this->context->getService(SiteSettings::class)->get('hotline', '')) ?? '';
            if ($digits !== '') {
                return $digits;
            }
        }
        return '';
    }

    private function resolveNotifyEmail(): string
    {
        return $this->context->hasService(SiteSettings::class)
            ? trim($this->context->getService(SiteSettings::class)->get('email', ''))
            : '';
    }
}
