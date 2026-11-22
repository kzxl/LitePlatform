<?php

declare(strict_types=1);

namespace LiteAdmin;

use LiteAdmin\Field\{FieldInterface, FieldRegistry};
use LiteAdmin\Metric\AdminMetric;
use LiteAdmin\Resource\{ResourceManager, ResourceMetadata};
use LiteAdmin\Security\{Csrf, PermissionGate};
use LiteAdmin\UI\HtmlRenderer;
use LiteAudit\AuditManager;
use LiteExport\Exporter;
use LiteORM\EntityManager;
use LiteValidate\Validator;
use Nyholm\Psr7\Response;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use RuntimeException;

/**
 * Main coordinator and router for the LiteAdmin auto-CRUD dashboard.
 */
class AdminDashboard
{
    private EntityManager $em;
    private ?AuditManager $auditManager;
    private string $prefix;
    private ResourceManager $resources;
    private HtmlRenderer $renderer;
    private FieldRegistry $fields;
    private Csrf $csrf;
    private bool $csrfEnabled = true;
    private PermissionGate $gate;
    /** @var AdminMetric[] */
    private array $metrics = [];
    /** @var list<callable(string, ResourceMetadata, object): void> */
    private array $mutationListeners = [];
    /** @var array<string, array{slug: string, title: string, group: string, get: callable|array|string, post: callable|array|string|null, permission: ?string}> */
    private array $customPages = [];
    /** @var list<array{method: string, path: string, handler: callable|array|string, permission: ?string}> */
    private array $customActions = [];

    public function __construct(
        EntityManager $em,
        ?AuditManager $auditManager = null,
        string $prefix = '/admin',
        ?Csrf $csrf = null,
        ?PermissionGate $gate = null,
        ?FieldRegistry $fields = null,
    ) {
        $this->em = $em;
        $this->auditManager = $auditManager;
        $this->prefix = rtrim($prefix, '/');
        $this->resources = new ResourceManager();
        $this->fields = $fields ?? new FieldRegistry();
        $this->renderer = new HtmlRenderer($this->prefix, [], $this->fields, []);
        $this->csrf = $csrf ?? new Csrf();
        $this->gate = $gate ?? new PermissionGate();
    }

    public function setCsrfEnabled(bool $enabled): self
    {
        $this->csrfEnabled = $enabled;
        return $this;
    }

    public function isCsrfEnabled(): bool
    {
        return $this->csrfEnabled;
    }

    public function getCsrf(): Csrf
    {
        return $this->csrf;
    }

    public function getPermissionGate(): PermissionGate
    {
        return $this->gate;
    }

    public function setPermissionGate(PermissionGate $gate): self
    {
        $this->gate = $gate;
        return $this;
    }

    public function setUserResolver(callable $resolver): self
    {
        $this->gate->setUserResolver($resolver);
        return $this;
    }

    /**
     * Register a listener invoked after create/update/delete flushes (e.g., PageCache / Sitemap invalidation).
     *
     * @param callable(string, ResourceMetadata, object): void $listener
     */
    public function onMutation(callable $listener): self
    {
        $this->mutationListeners[] = $listener;
        return $this;
    }

    private function notifyMutation(string $action, ResourceMetadata $res, object $entity): void
    {
        foreach ($this->mutationListeners as $listener) {
            $listener($action, $res, $entity);
        }
    }

    private function resolveCsrfToken(ServerRequestInterface $request): ?string
    {
        $external = $request->getAttribute('csrf_token');
        if (is_string($external) && $external !== '') {
            return $external;
        }

        return $this->csrfEnabled ? $this->csrf->generateToken() : null;
    }

    /**
     * Register an auditable, validatable entity with the admin panel.
     */
    public function register(string $entityClass): self
    {
        $this->resources->register($entityClass);
        $this->refreshRenderer();
        return $this;
    }

    /**
     * Register a custom pluggable form field type (e.g. 'map', 'color', 'gallery').
     *
     * @param FieldInterface|callable(array<string, mixed>, mixed, array<string, mixed>): string $renderer
     */
    public function registerField(string $type, FieldInterface|callable $renderer): self
    {
        $this->fields->register($type, $renderer);
        return $this;
    }

    public function getFieldRegistry(): FieldRegistry
    {
        return $this->fields;
    }

    /**
     * Register a custom Admin sidebar page (e.g. Settings, Media Library, Analytics).
     *
     * @param callable|array<int, string>|string $getHandler
     * @param callable|array<int, string>|string|null $postHandler
     */
    public function registerPage(
        string $slug,
        string $title,
        string $group,
        callable|array|string $getHandler,
        callable|array|string|null $postHandler = null,
        ?string $permission = null
    ): self {
        $slug = trim($slug, '/');
        $this->customPages[$slug] = [
            'slug'       => $slug,
            'title'      => $title,
            'group'      => $group,
            'get'        => $getHandler,
            'post'       => $postHandler,
            'permission' => $permission,
        ];
        $this->refreshRenderer();
        return $this;
    }

    /**
     * Register a non-sidebar Admin sub-route (e.g. export, JSON endpoint, print view)
     * mounted BEFORE generic /{slug} routes so static paths are never shadowed by FastRoute.
     *
     * @param callable|array<int, string>|string $handler
     */
    public function registerAction(
        string $method,
        string $subPath,
        callable|array|string $handler,
        ?string $permission = null
    ): self {
        $this->customActions[] = [
            'method'     => strtoupper($method),
            'path'       => trim($subPath, '/'),
            'handler'    => $handler,
            'permission' => $permission,
        ];
        return $this;
    }

    private function refreshRenderer(): void
    {
        $groupedPages = [];
        foreach ($this->customPages as $page) {
            $groupedPages[$page['group']][] = [
                'slug'  => $page['slug'],
                'title' => $page['title'],
            ];
        }
        $this->renderer = new HtmlRenderer(
            $this->prefix,
            $this->resources->getResourcesByGroup(),
            $this->fields,
            $groupedPages
        );
    }

    public function addMetric(AdminMetric $metric): self
    {
        $this->metrics[] = $metric;
        return $this;
    }

    /**
     * @return AdminMetric[]
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getResourceManager(): ResourceManager
    {
        return $this->resources;
    }

    public function getHtmlRenderer(): HtmlRenderer
    {
        return $this->renderer;
    }

    /**
     * Register admin dashboard routes on a Slim 4 App.
     *
     * @param \Slim\App $app
     */
    public function registerRoutes(object $app): void
    {
        $prefix = $this->prefix;

        $app->get($prefix, [$this, 'handleIndex']);

        // Mount custom pages & actions BEFORE generic /{slug} routes so they take precedence
        $gate = $this->gate;
        $renderer = $this->renderer;
        $makeGuard = static function (string $perm) use ($gate, $renderer): \Psr\Http\Server\MiddlewareInterface {
            return new class ($gate, $renderer, $perm) implements \Psr\Http\Server\MiddlewareInterface {
                public function __construct(
                    private readonly PermissionGate $gate,
                    private readonly HtmlRenderer $renderer,
                    private readonly string $perm
                ) {
                }

                public function process(
                    ServerRequestInterface $request,
                    \Psr\Http\Server\RequestHandlerInterface $handler
                ): ResponseInterface {
                    if (!$this->gate->hasPermission($request, $this->perm)) {
                        $res = new Response(403, ['Content-Type' => 'text/html; charset=UTF-8']);
                        $msg = 'Missing permission: ' . htmlspecialchars($this->perm, ENT_QUOTES, 'UTF-8');
                        $res->getBody()->write($this->renderer->layout('Access Denied', "<div class=\"card\"><h2>403 Forbidden</h2><p style=\"margin-top:0.5rem;color:var(--text-muted);\">{$msg}</p></div>", ''));
                        return $res;
                    }
                    return $handler->handle($request);
                }
            };
        };

        foreach ($this->customPages as $page) {
            $getRoute = $app->get($prefix . '/' . $page['slug'], $page['get']);
            if ($page['permission'] !== null) {
                $getRoute->add($makeGuard($page['permission']));
            }
            if ($page['post'] !== null) {
                $postRoute = $app->post($prefix . '/' . $page['slug'], $page['post']);
                if ($page['permission'] !== null) {
                    $postRoute->add($makeGuard($page['permission']));
                }
            }
        }
        foreach ($this->customActions as $action) {
            $actRoute = $app->map([$action['method']], $prefix . '/' . $action['path'], $action['handler']);
            if ($action['permission'] !== null) {
                $actRoute->add($makeGuard($action['permission']));
            }
        }

        $app->get($prefix . '/{slug}', [$this, 'handleList']);
        $app->get($prefix . '/{slug}/create', [$this, 'handleCreate']);
        $app->post($prefix . '/{slug}/create', [$this, 'handleStore']);
        $app->get($prefix . '/{slug}/edit/{id}', [$this, 'handleEdit']);
        $app->post($prefix . '/{slug}/edit/{id}', [$this, 'handleUpdate']);
        $app->get($prefix . '/{slug}/detail/{id}', [$this, 'handleDetail']);
        $app->post($prefix . '/{slug}/delete/{id}', [$this, 'handleDelete']);
        $app->get($prefix . '/{slug}/export', [$this, 'handleExport']);
    }

    // ─── Route Handlers ───────────────────────────────────────────

    public function handleIndex(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $allResources = $this->resources->getResources();
        $resources = array_filter($allResources, fn($res) => $this->gate->can($request, $res->entityClass, 'view'));

        if (!empty($this->metrics)) {
            $metricsHtml = '<div class="metrics-grid">';
            foreach ($this->metrics as $metric) {
                $metricsHtml .= $metric->render();
            }
            $metricsHtml .= '</div>';

            $content = '<div class="page-header"><h1>System Overview</h1></div>';
            $content .= $metricsHtml;

            if (!empty($resources)) {
                $content .= '<div class="card"><h3 style="margin-bottom: 0.75rem;">Registered Resources</h3><ul style="padding-left: 1.5rem; line-height: 1.8;">';
                foreach ($resources as $res) {
                    $content .= "<li><a href=\"{$this->prefix}/{$res->slug}\">{$res->title}</a></li>";
                }
                $content .= '</ul></div>';
            }

            $html = $this->renderer->layout('Dashboard', $content);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        if (!empty($resources)) {
            $firstSlug = array_key_first($resources);
            return $response->withHeader('Location', "{$this->prefix}/{$firstSlug}")->withStatus(302);
        }

        $html = $this->renderer->layout('Admin Dashboard', '<div class="card">No resources registered yet.</div>');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function handleList(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        $res = $this->resolveResource($slug);

        if (!$this->gate->can($request, $res->entityClass, 'view')) {
            return $this->forbiddenResponse($response, 'You do not have permission to view this resource.');
        }

        $queryParams = $request->getQueryParams();

        $page = max(1, (int)($queryParams['page'] ?? 1));
        $perPage = 15;
        $search = trim((string)($queryParams['search'] ?? ''));

        // Query entities using LiteORM QueryBuilder
        $qb = $this->em->query($res->entityClass);

        if ($search !== '') {
            $searchableCols = array_filter($res->columns, fn($c) => $c['searchable']);
            $first = true;
            foreach ($searchableCols as $prop => $col) {
                if ($first) {
                    $qb->where($prop, 'LIKE', "%{$search}%");
                    $first = false;
                } else {
                    $qb->orWhere($prop, 'LIKE', "%{$search}%");
                }
            }
        }

        // Whitelist-based sort column check (SQL Injection & Invalid Column Prevention)
        $sort = $queryParams['sort'] ?? null;
        $order = strtoupper((string)($queryParams['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        if ($sort && isset($res->columns[$sort])) {
            $qb->orderBy($sort, $order);
        }

        $paginator = $qb->paginate($page, $perPage);

        $csrfToken = $this->resolveCsrfToken($request);
        $content = $this->renderer->renderList($res, $paginator, $queryParams, $csrfToken);
        $html = $this->renderer->layout($res->title, $content, $res->slug);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function handleCreate(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'create')) {
            return $this->forbiddenResponse($response, 'You do not have permission to create this resource.');
        }

        $csrfToken = $this->resolveCsrfToken($request);
        $content = $this->renderer->renderForm($res, csrfToken: $csrfToken, relationOptions: $this->resolveRelationOptions($res));
        $html = $this->renderer->layout("Create {$res->title}", $content, $res->slug);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function handleStore(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->csrfEnabled && !$this->verifyCsrf($request)) {
            return $this->csrfForbiddenResponse($response);
        }

        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'create')) {
            return $this->forbiddenResponse($response, 'You do not have permission to create this resource.');
        }

        $data = (array)$request->getParsedBody();

        // 1. Validate using LiteValidate if entity has attributes
        $entityClass = $res->entityClass;
        $errors = $this->validateInput($entityClass, $data);

        if (!empty($errors)) {
            $csrfToken = $this->resolveCsrfToken($request);
            $content = $this->renderer->renderForm($res, null, $errors, $data, $csrfToken, $this->resolveRelationOptions($res));
            $html = $this->renderer->layout("Create {$res->title}", $content, $res->slug, flashError: 'Please review the form for errors.');
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')->withStatus(422);
        }

        // 2. Hydrate & Persist via LiteORM
        $entity = new $entityClass();
        $this->hydrateProperties($entity, $res, $data);

        $this->em->persist($entity);
        $this->em->flush();
        $this->notifyMutation('create', $res, $entity);

        return $response->withHeader('Location', "{$this->prefix}/{$res->slug}")->withStatus(302);
    }

    public function handleEdit(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'update')) {
            return $this->forbiddenResponse($response, 'You do not have permission to edit this resource.');
        }

        $id = $args['id'] ?? '';
        $entity = $this->em->find($res->entityClass, $id);

        if (!$entity) {
            return $response->withHeader('Location', "{$this->prefix}/{$res->slug}")->withStatus(302);
        }

        $csrfToken = $this->resolveCsrfToken($request);
        $content = $this->renderer->renderForm($res, $entity, csrfToken: $csrfToken, relationOptions: $this->resolveRelationOptions($res));
        $html = $this->renderer->layout("Edit {$res->title}", $content, $res->slug);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function handleUpdate(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->csrfEnabled && !$this->verifyCsrf($request)) {
            return $this->csrfForbiddenResponse($response);
        }

        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'update')) {
            return $this->forbiddenResponse($response, 'You do not have permission to update this resource.');
        }

        $id = $args['id'] ?? '';
        $entity = $this->em->find($res->entityClass, $id);

        if (!$entity) {
            return $response->withHeader('Location', "{$this->prefix}/{$res->slug}")->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $errors = $this->validateInput($res->entityClass, $data);

        if (!empty($errors)) {
            $csrfToken = $this->resolveCsrfToken($request);
            $content = $this->renderer->renderForm($res, $entity, $errors, $data, $csrfToken, $this->resolveRelationOptions($res));
            $html = $this->renderer->layout("Edit {$res->title}", $content, $res->slug, flashError: 'Please review the form for errors.');
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')->withStatus(422);
        }

        $this->hydrateProperties($entity, $res, $data);
        $this->em->flush();
        $this->notifyMutation('update', $res, $entity);

        return $response->withHeader('Location', "{$this->prefix}/{$res->slug}")->withStatus(302);
    }

    public function handleDetail(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'view')) {
            return $this->forbiddenResponse($response, 'You do not have permission to view details of this resource.');
        }

        $id = $args['id'] ?? '';
        $entity = $this->em->find($res->entityClass, $id);

        if (!$entity) {
            return $response->withHeader('Location', "{$this->prefix}/{$res->slug}")->withStatus(302);
        }

        // Fetch audit history if LiteAudit is attached
        $auditHistory = [];
        if ($this->auditManager !== null) {
            $auditHistory = $this->auditManager->getHistory($res->entityClass, $id, limit: 20);
        }

        $content = $this->renderer->renderDetail($res, $entity, $auditHistory);
        $html = $this->renderer->layout("Details for {$res->title} #{$id}", $content, $res->slug);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function handleDelete(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        if ($this->csrfEnabled && !$this->verifyCsrf($request)) {
            return $this->csrfForbiddenResponse($response);
        }

        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'delete')) {
            return $this->forbiddenResponse($response, 'You do not have permission to delete this resource.');
        }

        $id = $args['id'] ?? '';
        $entity = $this->em->find($res->entityClass, $id);

        if ($entity) {
            $this->em->remove($entity);
            $this->em->flush();
            $this->notifyMutation('delete', $res, $entity);
        }

        return $response->withHeader('Location', "{$this->prefix}/{$res->slug}")->withStatus(302);
    }

    public function handleExport(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $res = $this->resolveResource($args['slug'] ?? '');

        if (!$this->gate->can($request, $res->entityClass, 'export')) {
            return $this->forbiddenResponse($response, 'You do not have permission to export this resource.');
        }

        // Stream all entities via LiteORM cursor generator
        $cursor = $this->em->query($res->entityClass)->cursor();

        $rowsGenerator = (function () use ($cursor, $res) {
            foreach ($cursor as $item) {
                $row = [];
                foreach ($res->columns as $prop => $col) {
                    $val = $item->{$prop} ?? null;
                    if ($val instanceof \DateTimeInterface) {
                        $val = $val->format('Y-m-d H:i:s');
                    }
                    $row[] = (string)$val;
                }
                yield $row;
            }
        })();

        $headers = array_column($res->columns, 'label');
        $filename = "{$res->slug}_export_" . date('Ymd_His') . '.xlsx';

        $xlsxBinary = (string)Exporter::xlsx($rowsGenerator, headers: $headers);
        $response->getBody()->write($xlsxBinary);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function resolveResource(string $slug): ResourceMetadata
    {
        $res = $this->resources->getResource($slug);
        if (!$res) {
            throw new RuntimeException("Resource with slug '{$slug}' not found.");
        }
        return $res;
    }

    /**
     * @return array<string, string>
     */
    private function validateInput(string $entityClass, array $data): array
    {
        try {
            $result = Validator::validate($data, $entityClass);
            if ($result->isFailed()) {
                $flatErrors = [];
                foreach ($result->getErrors() as $field => $messages) {
                    $flatErrors[$field] = $messages[0] ?? 'Invalid value';
                }
                return $flatErrors;
            }
        } catch (\Throwable $e) {
            // If class does not have validation rules, continue
        }

        return [];
    }

    private function verifyCsrf(ServerRequestInterface $request): bool
    {
        $body = (array)$request->getParsedBody();
        $token = $body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        return $this->csrf->validateToken(is_string($token) ? $token : null);
    }

    private function csrfForbiddenResponse(ResponseInterface $response): ResponseInterface
    {
        $html = $this->renderer->layout(
            '403 Forbidden',
            '<div class="card" style="border-left: 4px solid var(--danger); padding: 1.5rem;">' .
            '<h2 style="color: var(--danger); margin-bottom: 0.5rem;">Security Error (CSRF Verification Failed)</h2>' .
            '<p>The request was rejected because the CSRF token is invalid or expired. Please refresh the page and try again.</p>' .
            '</div>'
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')->withStatus(403);
    }

    private function forbiddenResponse(ResponseInterface $response, string $message = 'You do not have permission to access this resource.'): ResponseInterface
    {
        $html = $this->renderer->layout(
            '403 Forbidden',
            '<div class="card" style="border-left: 4px solid var(--danger); padding: 1.5rem;">' .
            '<h2 style="color: var(--danger); margin-bottom: 0.5rem;">Access Denied (403 Forbidden)</h2>' .
            '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>' .
            '</div>'
        );
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')->withStatus(403);
    }

    /**
     * Resolve dynamic dropdown options for fields tagged with relation: 'table:value_col:label_col'.
     *
     * @return array<string, array<string|int, string>>
     */
    private function resolveRelationOptions(ResourceMetadata $res): array
    {
        $resolved = [];
        $pdo = null;

        foreach ($res->fields as $field) {
            $spec = $field['relation'] ?? null;
            if (!is_string($spec) || $spec === '') {
                continue;
            }
            $parts = explode(':', $spec);
            if (count($parts) !== 3) {
                continue;
            }
            [$table, $valCol, $labelCol] = $parts;
            foreach ([$table, $valCol, $labelCol] as $ident) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $ident)) {
                    continue 2;
                }
            }

            if ($pdo === null) {
                $getPdo = \Closure::bind(fn() => $this->conn->getReadConnection(), $this->em, EntityManager::class);
                $pdo = $getPdo();
            }

            try {
                $stmt = $pdo->query("SELECT {$valCol}, {$labelCol} FROM {$table} ORDER BY {$labelCol} ASC LIMIT 500");
                $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                $opts = [];
                foreach ($rows as $row) {
                    $opts[(string)$row[$valCol]] = (string)$row[$labelCol];
                }
                $resolved[$field['property']] = $opts;
            } catch (\Throwable) {
                $resolved[$field['property']] = [];
            }
        }

        return $resolved;
    }

    private function hydrateProperties(object $entity, ResourceMetadata $res, array $data): void
    {
        $refClass = new \ReflectionClass($entity);

        foreach ($res->fields as $field) {
            $prop = $field['property'];
            // Mass assignment protection: skip primary key and readonly properties
            if ($prop === $res->primaryKey || !empty($field['readonly'])) {
                continue;
            }

            $refProp = $refClass->hasProperty($prop) ? $refClass->getProperty($prop) : null;
            $propType = $refProp?->getType();
            $isNullable = $propType === null || $propType->allowsNull();
            $typeName = $propType instanceof \ReflectionNamedType ? $propType->getName() : null;

            if (array_key_exists($prop, $data)) {
                $val = $data[$prop];
                $type = $field['type'];

                // Server-side auto-slug fallback when slug field left blank
                if ($type === 'slug' && trim((string)$val) === '' && !empty($field['slugFrom'])) {
                    $sourceVal = (string)($data[$field['slugFrom']] ?? '');
                    if ($sourceVal !== '' && class_exists(\LiteUtil\Text\Str::class)) {
                        $val = \LiteUtil\Text\Str::slug($sourceVal);
                    }
                }

                if ($val === '' && $isNullable && in_array($typeName, ['int', 'float', 'DateTimeImmutable', 'DateTime'], true)) {
                    $entity->{$prop} = null;
                    continue;
                }

                $entity->{$prop} = match (true) {
                    $type === 'checkbox' || $typeName === 'bool' => (bool)$val,
                    $type === 'datetime' || $typeName === 'DateTimeImmutable' => !empty($val) ? new \DateTimeImmutable((string)$val) : null,
                    $typeName === 'int' => is_numeric($val) ? (int)$val : ($isNullable ? null : 0),
                    $typeName === 'float' || $type === 'number' => is_numeric($val) ? (float)$val : ($isNullable ? null : 0.0),
                    default => ($val === '' && $isNullable && $typeName === 'string') ? null : (string)$val,
                };
            } elseif ($field['type'] === 'checkbox') {
                $entity->{$prop} = false;
            }
        }
    }
}
