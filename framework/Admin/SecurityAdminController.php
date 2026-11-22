<?php

declare(strict_types=1);

namespace Lite\Framework\Admin;

use DI\Container;
use Lite\Framework\Console\DatabaseBackupService;
use Lite\Framework\Core\Plugin\BlueprintManager;
use Lite\Framework\Core\Plugin\HardenedPluginInterface;
use Lite\Framework\Core\Plugin\ModuleManager;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Plugin\PluginLockManager;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecurityAuditLog;
use LiteAdmin\AdminDashboard;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Interactive Security, Plugin Governance (/admin/security), and RBAC Matrix (/admin/roles) Controller.
 */
final class SecurityAdminController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->renderSecurityPage($request, $response);
    }

    public function handleAction(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $action = trim((string)($body['action'] ?? ''));
        $actor = (string)$request->getAttribute('admin_user', 'admin');
        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $audit = $this->container->has(SecurityAuditLog::class) ? $this->container->get(SecurityAuditLog::class) : null;
        $flash = null;

        if ($action === 'set_plugin_state' && $this->container->has(PDO::class)) {
            $pluginId = trim((string)($body['plugin_id'] ?? ''));
            $newState = trim((string)($body['state'] ?? 'enabled'));
            if ($pluginId !== '' && in_array($newState, ['enabled', 'disabled'], true)) {
                /** @var PDO $pdo */
                $pdo = $this->container->get(PDO::class);
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $now = date('Y-m-d H:i:s');
                if ($driver === 'mysql') {
                    $sql = 'INSERT INTO plugin_registry (plugin_id, version, state, failure_count, last_error, updated_at)
                            VALUES (:id, \'1.0.0\', :st, 0, NULL, :u)
                            ON DUPLICATE KEY UPDATE state = VALUES(state), failure_count = 0, last_error = NULL, updated_at = VALUES(updated_at)';
                } else {
                    $sql = 'INSERT INTO plugin_registry (plugin_id, version, state, failure_count, last_error, updated_at)
                            VALUES (:id, \'1.0.0\', :st, 0, NULL, :u)
                            ON CONFLICT(plugin_id) DO UPDATE SET state = excluded.state, failure_count = 0, last_error = NULL, updated_at = excluded.updated_at';
                }
                $pdo->prepare($sql)->execute([':id' => $pluginId, ':st' => $newState, ':u' => $now]);
                $audit?->record('plugin.state_changed', 'HIGH', $actor, $ip ?: null, $pluginId, $newState);
                $flash = "Plugin '{$pluginId}' state updated to [{$newState}].";
            }
        } elseif ($action === 'generate_lock') {
            $basePath = (string)($this->container->get('config')['base_path'] ?? dirname(__DIR__, 2));
            $lock = (new PluginLockManager($basePath))->generateLock();
            $audit?->record('plugin.lock_regenerated', 'HIGH', $actor, $ip ?: null, 'core', 'plugins.lock.json', ['plugins' => array_keys($lock)]);
            $flash = 'Regenerated plugins.lock.json (' . count($lock) . ' plugin checksums locked).';
        } elseif ($action === 'revoke_user' && $this->container->has(RbacManager::class)) {
            $uid = (int)($body['user_id'] ?? 0);
            if ($uid > 0) {
                /** @var RbacManager $rbac */
                $rbac = $this->container->get(RbacManager::class);
                $newVer = $rbac->revokeUserSessions($uid);
                $audit?->record('auth.sessions_revoked_manual', 'HIGH', $actor, $ip ?: null, 'core', 'user#' . $uid, ['token_version' => $newVer]);
                $flash = "Revoked all active sessions for User #{$uid} (token_version -> v{$newVer}).";
            }
        } elseif ($action === 'apply_blueprint' && $this->container->has(PDO::class)) {
            $bpKey = trim((string)($body['blueprint'] ?? ''));
            if ($bpKey !== '') {
                $bpMgr = new BlueprintManager($this->container->get(PDO::class), $audit);
                $res = $bpMgr->apply($bpKey, $actor, $ip ?: null);
                $flash = "Applied Industry Blueprint [{$res['name']}]. Reload page to see active extensions.";
            }
        } elseif ($action === 'create_backup' && $this->container->has(PDO::class)) {
            $basePath = (string)($this->container->get('config')['base_path'] ?? dirname(__DIR__, 2));
            $bk = (new DatabaseBackupService($this->container->get(PDO::class), $basePath . '/storage/backups'))->createBackup(7);
            $audit?->record('backup.created', 'INFO', $actor, $ip ?: null, 'core', $bk['filename'], ['tables' => $bk['tables'], 'rows' => $bk['rows']]);
            $flash = "Created database backup: {$bk['filename']} ({$bk['tables']} tables, {$bk['rows']} rows).";
        }

        return $this->renderSecurityPage($request, $response, $flash);
    }

    public function showRoles(Request $request, Response $response): Response
    {
        return $this->renderRolesPage($request, $response);
    }

    public function saveRoles(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        $roleId = (int)($body['role_id'] ?? 0);
        $permIds = array_map('intval', (array)($body['permissions'] ?? []));
        $actor = (string)$request->getAttribute('admin_user', 'admin');
        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($roleId > 0 && $this->container->has(RbacManager::class)) {
            /** @var RbacManager $rbac */
            $rbac = $this->container->get(RbacManager::class);
            $rbac->setRolePermissions($roleId, $permIds);

            if ($this->container->has(SecurityAuditLog::class)) {
                $this->container->get(SecurityAuditLog::class)->record(
                    eventType: 'rbac.role_updated',
                    severity: 'HIGH',
                    actor: $actor,
                    actorIp: $ip !== '' ? $ip : null,
                    moduleId: 'core',
                    target: 'role#' . $roleId,
                    context: ['permission_ids' => $permIds]
                );
            }
        }

        return $this->renderRolesPage($request, $response, 'Role permissions updated and RBAC cache refreshed.');
    }

    private function renderSecurityPage(Request $request, Response $response, ?string $flashSuccess = null): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $basePath = (string)($this->container->get('config')['base_path'] ?? dirname(__DIR__, 2));

        // 1. Audit chain verification
        $chain = ['ok' => true, 'checked' => 0, 'broken_at_id' => null, 'reason' => null];
        $events = [];
        if ($this->container->has(SecurityAuditLog::class)) {
            try {
                /** @var SecurityAuditLog $audit */
                $audit = $this->container->get(SecurityAuditLog::class);
                $chain = $audit->verifyChain();
                $events = $audit->recent(25);
            } catch (\Throwable) {
            }
        }

        // 2. Lockfile integrity verification
        $lockStatus = (new PluginLockManager($basePath))->verifyLock();
        $lockBadge = !$lockStatus['has_lockfile']
            ? '<span class="badge" style="background:#fef3c7;color:#92400e;">Not Locked Yet</span>'
            : ($lockStatus['ok']
                ? '<span class="badge badge-success">ALL VERIFIED</span>'
                : '<span class="badge badge-danger">MISMATCH / TAMPERED</span>');

        // 3. Plugin inventory & 1-click enable/disable controls
        $pluginRows = '';
        $quarantinedCount = 0;
        $activeCount = 0;
        if ($this->container->has(ModuleManagerInterface::class)) {
            $mm = $this->container->get(ModuleManagerInterface::class);
            foreach ($mm->getModules() as $id => $mod) {
                $isQ = ($mm instanceof ModuleManager) && $mm->isQuarantined($id);
                if ($isQ) {
                    $quarantinedCount++;
                } else {
                    $activeCount++;
                }
                $badge = $isQ
                    ? '<span class="badge badge-danger">Disabled / Quarantined</span>'
                    : '<span class="badge badge-success">Active</span>';
                $mode = $mod instanceof HardenedPluginInterface ? 'LES v1 Sandboxed' : 'Legacy';
                $caps = $mod instanceof HardenedPluginInterface
                    ? implode(', ', array_map(static fn($c) => $c->value, $mod->manifest()->capabilities))
                    : 'Unrestricted (legacy)';
                $nextState = $isQ ? 'enabled' : 'disabled';
                $btnLabel = $isQ ? 'Enable' : 'Disable';

                $pluginRows .= '<tr>'
                    . '<td><code>' . $e($id) . '</code></td>'
                    . '<td>' . $e($mod->getName()) . ' <small style="color:var(--text-muted);">v' . $e($mod->getVersion()) . '</small></td>'
                    . '<td>' . $badge . '</td>'
                    . '<td>' . $e($mode) . '</td>'
                    . '<td style="font-size:0.78rem;color:var(--text-muted);">' . $e($caps ?: 'None') . '</td>'
                    . '<td><form method="POST" action="/admin/security" style="display:inline;">'
                    . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                    . '<input type="hidden" name="action" value="set_plugin_state">'
                    . '<input type="hidden" name="plugin_id" value="' . $e($id) . '">'
                    . '<input type="hidden" name="state" value="' . $nextState . '">'
                    . '<button type="submit" class="btn btn-outline btn-sm">' . $btnLabel . '</button>'
                    . '</form></td>'
                    . '</tr>';
            }
        }
        if ($pluginRows === '') {
            $pluginRows = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No plugins registered.</td></tr>';
        }

        // 4. Active users session revocation list
        $userRows = '';
        if ($this->container->has(PDO::class)) {
            try {
                $users = $this->container->get(PDO::class)->query('SELECT id, name, email, role, COALESCE(token_version, 1) AS token_version, COALESCE(is_active, 1) AS is_active FROM users ORDER BY id ASC LIMIT 20')?->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($users as $u) {
                    $userRows .= '<tr>'
                        . '<td>' . $e((string)$u['name']) . '</td>'
                        . '<td>' . $e((string)$u['email']) . '</td>'
                        . '<td><code>' . $e((string)$u['role']) . '</code></td>'
                        . '<td>v' . (int)$u['token_version'] . '</td>'
                        . '<td><form method="POST" action="/admin/security" style="display:inline;">'
                        . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                        . '<input type="hidden" name="action" value="revoke_user">'
                        . '<input type="hidden" name="user_id" value="' . (int)$u['id'] . '">'
                        . '<button type="submit" class="btn btn-outline btn-sm">Revoke Sessions</button>'
                        . '</form></td>'
                        . '</tr>';
                }
            } catch (\Throwable) {
            }
        }

        $eventRows = '';
        foreach ($events as $ev) {
            $sev = (string)($ev['severity'] ?? 'INFO');
            $sevClass = in_array($sev, ['CRITICAL', 'HIGH'], true) ? 'badge-danger' : 'badge-success';
            $eventRows .= '<tr>'
                . '<td style="white-space:nowrap;font-size:0.78rem;">' . $e((string)$ev['occurred_at']) . '</td>'
                . '<td><span class="badge ' . $sevClass . '">' . $e($sev) . '</span></td>'
                . '<td><code>' . $e((string)$ev['event_type']) . '</code></td>'
                . '<td>' . $e((string)$ev['actor']) . '</td>'
                . '<td>' . $e((string)$ev['module_id']) . '</td>'
                . '<td>' . $e((string)($ev['target'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($eventRows === '') {
            $eventRows = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No security events recorded yet.</td></tr>';
        }

        $chainBadge = $chain['ok']
            ? '<span class="badge badge-success">INTACT (' . (int)$chain['checked'] . ' entries)</span>'
            : '<span class="badge badge-danger">TAMPERED at #' . (int)$chain['broken_at_id'] . '</span>';

        // 5. Industry Blueprints & Backup list
        $blueprintCards = '';
        $backupsCount = 0;
        if ($this->container->has(PDO::class)) {
            $bpMgr = new BlueprintManager($this->container->get(PDO::class));
            foreach ($bpMgr->listBlueprints() as $bpKey => $bp) {
                $enabledText = empty($bp['enable']) ? 'Core CMS Only' : implode(', ', array_map(static fn(string $id): string => basename(str_replace('.', '/', $id)), $bp['enable']));
                $blueprintCards .= '<div style="border:1px solid var(--border);border-radius:8px;padding:0.9rem;display:flex;flex-direction:column;gap:0.4rem;">'
                    . '<strong>' . $e($bp['name']) . '</strong>'
                    . '<p style="font-size:0.8rem;color:var(--text-muted);flex:1;">' . $e($bp['description']) . '</p>'
                    . '<div style="font-size:0.75rem;color:var(--text-muted);">Modules: <code>' . $e($enabledText) . '</code></div>'
                    . '<form method="POST" action="/admin/security" style="margin-top:0.4rem;">'
                    . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                    . '<input type="hidden" name="action" value="apply_blueprint">'
                    . '<input type="hidden" name="blueprint" value="' . $e($bpKey) . '">'
                    . '<button type="submit" class="btn btn-outline btn-sm">Apply Blueprint</button>'
                    . '</form></div>';
            }
            $backupsCount = count((new DatabaseBackupService($this->container->get(PDO::class), $basePath . '/storage/backups'))->listBackups());
        }

        $content = <<<HTML
<div class="page-header" style="flex-wrap:wrap;gap:1rem;">
    <div>
        <h1>Security &amp; Plugin Governance</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">LES v1 capability isolation, Industry Blueprints, SHA-256 supply-chain lock, RBAC &amp; HMAC-chained audit trail.</p>
    </div>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <a href="/admin/roles" class="btn btn-outline">RBAC Role Matrix</a>
        <form method="POST" action="/admin/security" style="display:inline;">
            <input type="hidden" name="_csrf" value="{$csrf}">
            <input type="hidden" name="action" value="create_backup">
            <button type="submit" class="btn btn-outline">Backup DB Now ({$backupsCount})</button>
        </form>
        <form method="POST" action="/admin/security" style="display:inline;">
            <input type="hidden" name="_csrf" value="{$csrf}">
            <input type="hidden" name="action" value="generate_lock">
            <button type="submit" class="btn btn-primary">Generate plugins.lock.json</button>
        </form>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Audit Chain Integrity</span></div>
        <div style="margin-top:0.4rem;">{$chainBadge}</div>
        <div class="metric-desc">HMAC-SHA256 linked rows</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Plugin Integrity Lock</span></div>
        <div style="margin-top:0.4rem;">{$lockBadge}</div>
        <div class="metric-desc">plugins.lock.json SHA-256 verification</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Active Plugins</span></div>
        <div class="metric-value">{$activeCount}</div>
        <div class="metric-desc">Disabled / Quarantined: {$quarantinedCount}</div>
    </div>
</div>

<div class="card">
    <div class="section-card-title">1-Click Industry Blueprints (Multi-Domain Extension Presets)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:0.85rem;">
        {$blueprintCards}
    </div>
</div>

<div class="card">
    <div class="section-card-title">Installed Plugins &amp; Capability Matrix</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Plugin ID</th><th>Name</th><th>State</th><th>Isolation</th><th>Declared Capabilities</th><th>Control</th></tr>
            </thead>
            <tbody>{$pluginRows}</tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="section-card-title">User Session Revocation (Instant token_version Invalidation)</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Token Ver</th><th>Action</th></tr>
            </thead>
            <tbody>{$userRows}</tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="section-card-title">Recent Security Audit Trail</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Timestamp (UTC)</th><th>Severity</th><th>Event</th><th>Actor</th><th>Module</th><th>Target</th></tr>
            </thead>
            <tbody>{$eventRows}</tbody>
        </table>
    </div>
</div>
HTML;

        $html = $admin->getHtmlRenderer()->layout('Security & Plugins', $content, 'security', flashSuccess: $flashSuccess);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderRolesPage(Request $request, Response $response, ?string $flashSuccess = null): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $matrix = ['roles' => [], 'permissions' => [], 'grants' => []];
        if ($this->container->has(RbacManager::class)) {
            $matrix = $this->container->get(RbacManager::class)->getMatrix();
        }

        $cards = '';
        foreach ($matrix['roles'] as $role) {
            $rid = (int)$role['id'];
            $slug = (string)$role['slug'];
            $isSuper = in_array(strtolower($slug), ['admin', 'superadmin'], true);
            $granted = $matrix['grants'][$rid] ?? [];

            $checks = '';
            foreach ($matrix['permissions'] as $perm) {
                $pid = (int)$perm['id'];
                $checked = ($isSuper || in_array($pid, $granted, true)) ? 'checked' : '';
                $disabled = $isSuper ? 'disabled' : '';
                $checks .= '<label style="display:flex;align-items:center;gap:0.5rem;padding:0.35rem 0;font-size:0.86rem;cursor:pointer;">'
                    . '<input type="checkbox" name="permissions[]" value="' . $pid . '" ' . $checked . ' ' . $disabled . '>'
                    . '<code>' . $e((string)$perm['code']) . '</code> '
                    . '<span style="color:var(--text-muted);">— ' . $e((string)$perm['label']) . '</span>'
                    . '</label>';
            }

            if ($checks === '') {
                $checks = '<p style="color:var(--text-muted);font-size:0.85rem;">No plugin permissions registered yet.</p>';
            }

            $saveBtn = $isSuper
                ? '<span class="badge badge-success">Full Bypass (System Role)</span>'
                : '<button type="submit" class="btn btn-primary btn-sm">Save Permissions</button>';

            $cards .= <<<HTML
<div class="card">
    <form method="POST" action="/admin/roles">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="hidden" name="role_id" value="{$rid}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.85rem;">
            <div>
                <h3 style="font-size:1.02rem;font-weight:700;">{$e((string)$role['name'])}</h3>
                <code style="font-size:0.78rem;color:var(--text-muted);">{$e($slug)}</code>
            </div>
            {$saveBtn}
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(290px, 1fr));gap:0.2rem 1rem;">
            {$checks}
        </div>
    </form>
</div>
HTML;
        }

        $content = <<<HTML
<div class="page-header">
    <div>
        <h1>RBAC Role &amp; Permission Matrix</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">Granular plugin permissions auto-discovered via SecurityAwareModuleInterface.</p>
    </div>
    <a href="/admin/security" class="btn btn-outline">&larr; Security Center</a>
</div>
{$cards}
HTML;

        $html = $admin->getHtmlRenderer()->layout('RBAC Roles', $content, 'roles', flashSuccess: $flashSuccess);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
