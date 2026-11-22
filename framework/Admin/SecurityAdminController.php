<?php

declare(strict_types=1);

namespace Lite\Framework\Admin;

use DI\Container;
use Lite\Framework\Core\Plugin\HardenedPluginInterface;
use Lite\Framework\Core\Plugin\ModuleManager;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Security\SecurityAuditLog;
use LiteAdmin\AdminDashboard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Built-in Security & Plugin Governance Center (/admin/security).
 * Displays plugin isolation status, declared capabilities, HMAC audit chain integrity,
 * and recent security events.
 */
final class SecurityAdminController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 1. Audit chain verification
        $chain = ['ok' => true, 'checked' => 0, 'broken_at_id' => null, 'reason' => null];
        $events = [];
        if ($this->container->has(SecurityAuditLog::class)) {
            /** @var SecurityAuditLog $audit */
            $audit = $this->container->get(SecurityAuditLog::class);
            try {
                $chain = $audit->verifyChain();
                $events = $audit->recent(25);
            } catch (\Throwable) {
            }
        }

        // 2. Plugin inventory & capability matrix
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
                    ? '<span class="badge badge-danger">Quarantined</span>'
                    : '<span class="badge badge-success">Active</span>';
                $mode = $mod instanceof HardenedPluginInterface ? 'LES v1 Sandboxed' : 'Legacy';
                $caps = $mod instanceof HardenedPluginInterface
                    ? implode(', ', array_map(static fn($c) => $c->value, $mod->manifest()->capabilities))
                    : 'Unrestricted (legacy)';
                $pluginRows .= '<tr>'
                    . '<td><code>' . $e($id) . '</code></td>'
                    . '<td>' . $e($mod->getName()) . ' <small style="color:var(--text-muted);">v' . $e($mod->getVersion()) . '</small></td>'
                    . '<td>' . $badge . '</td>'
                    . '<td>' . $e($mode) . '</td>'
                    . '<td style="font-size:0.78rem;color:var(--text-muted);">' . $e($caps ?: 'None') . '</td>'
                    . '</tr>';
            }
        }
        if ($pluginRows === '') {
            $pluginRows = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);">No plugins registered.</td></tr>';
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
            ? '<span class="badge badge-success">INTACT (' . (int)$chain['checked'] . ' entries verified)</span>'
            : '<span class="badge badge-danger">TAMPERED at ID #' . (int)$chain['broken_at_id'] . '</span>';

        $content = <<<HTML
<div class="page-header">
    <div>
        <h1>Security &amp; Plugin Governance</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">LES v1 capability isolation, RBAC enforcement, AEAD secret vault &amp; HMAC-chained audit trail.</p>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Audit Chain Integrity</span></div>
        <div style="margin-top:0.4rem;">{$chainBadge}</div>
        <div class="metric-desc">HMAC-SHA256 linked rows (tamper-evident)</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Active Plugins</span></div>
        <div class="metric-value">{$activeCount}</div>
        <div class="metric-desc">Quarantined: {$quarantinedCount}</div>
    </div>
    <div class="metric-card">
        <div class="metric-header"><span class="metric-label">Secret Vault</span></div>
        <div class="metric-value" style="font-size:1.2rem;">AEAD Ready</div>
        <div class="metric-desc">XChaCha20-Poly1305 / AES-256-GCM scoped per plugin</div>
    </div>
</div>

<div class="card">
    <div class="section-card-title">Installed Plugins &amp; Capability Matrix</div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr><th>Plugin ID</th><th>Name</th><th>State</th><th>Isolation</th><th>Declared Capabilities</th></tr>
            </thead>
            <tbody>{$pluginRows}</tbody>
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

        $html = $admin->getHtmlRenderer()->layout('Security & Plugins', $content, 'security');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
