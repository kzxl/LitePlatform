<?php

declare(strict_types=1);

namespace Lite\Framework\Dev;

use DI\Container;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Built-in Zero-Dependency Database & Audit Studio (Active in debug mode only).
 */
class StudioController
{
    private ?PDO $pdo;
    private bool $isDebug;

    public function __construct(private Container $container)
    {
        $this->pdo = $container->has(PDO::class) ? $container->get(PDO::class) : null;
        $config = $container->has('config') ? $container->get('config') : [];
        $this->isDebug = (bool)($config['debug'] ?? true);
    }

    /**
     * GET /_studio - Render Studio Dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        if (!$this->isDebug) {
            $response->getBody()->write('Studio is disabled in production mode.');
            return $response->withStatus(403);
        }

        if (!$this->pdo) {
            $response->getBody()->write('Database is not configured.');
            return $response->withStatus(500);
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tablesSql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            'pgsql'  => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            default  => "SHOW TABLES",
        };

        $tableNames = $this->pdo->query($tablesSql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tables = [];
        foreach ($tableNames as $t) {
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $tables[$t] = $count;
        }

        $params = $request->getQueryParams();
        $selectedTable = $params['table'] ?? (!empty($tableNames) ? $tableNames[0] : null);

        $columns = [];
        $rows = [];
        $totalRows = 0;
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = 15;

        if ($selectedTable && isset($tables[$selectedTable])) {
            $totalRows = $tables[$selectedTable];
            $offset = ($page - 1) * $limit;

            if ($driver === 'sqlite') {
                $colsMeta = $this->pdo->query("PRAGMA table_info(`{$selectedTable}`)")->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_map(fn($c) => $c['name'], $colsMeta);
            } else {
                $colsMeta = $this->pdo->query("DESCRIBE `{$selectedTable}`")->fetchAll(PDO::FETCH_ASSOC);
                $columns = array_map(fn($c) => $c['Field'], $colsMeta);
            }

            $stmt = $this->pdo->query("SELECT * FROM `{$selectedTable}` LIMIT {$limit} OFFSET {$offset}");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $html = $this->renderHtml($driver, $tables, $selectedTable, $columns, $rows, $page, $totalRows, $limit);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * POST /_studio/query - Execute raw SQL query
     */
    public function executeQuery(Request $request, Response $response): Response
    {
        if (!$this->isDebug || !$this->pdo) {
            $response->getBody()->write(json_encode(['error' => 'Forbidden']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $body = (array)$request->getParsedBody();
        $sql = trim($body['sql'] ?? '');

        if ($sql === '') {
            $response->getBody()->write(json_encode(['error' => 'Empty SQL statement']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $t0 = hrtime(true);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $elapsedMs = round((hrtime(true) - $t0) / 1e6, 2);

            $isSelect = stripos(ltrim($sql), 'SELECT') === 0 || stripos(ltrim($sql), 'PRAGMA') === 0 || stripos(ltrim($sql), 'EXPLAIN') === 0;

            if ($isSelect) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $columns = !empty($rows) ? array_keys($rows[0]) : [];
                $resData = [
                    'success'    => true,
                    'is_select'  => true,
                    'columns'    => $columns,
                    'rows'       => $rows,
                    'count'      => count($rows),
                    'elapsed_ms' => $elapsedMs,
                ];
            } else {
                $resData = [
                    'success'       => true,
                    'is_select'     => false,
                    'affected_rows' => $stmt->rowCount(),
                    'elapsed_ms'    => $elapsedMs,
                ];
            }

            $response->getBody()->write(json_encode($resData));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error'   => $e->getMessage(),
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    private function renderHtml(
        string $driver,
        array $tables,
        ?string $selectedTable,
        array $columns,
        array $rows,
        int $page,
        int $totalRows,
        int $limit
    ): string {
        $totalPages = max(1, (int)ceil($totalRows / $limit));

        $tableItems = '';
        foreach ($tables as $tName => $tCount) {
            $activeClass = $tName === $selectedTable ? 'active' : '';
            $tableItems .= <<<HTML
                <a href="/_studio?table={$tName}" class="table-nav-item {$activeClass}">
                    <span>{$tName}</span>
                    <span class="badge">{$tCount}</span>
                </a>
HTML;
        }

        $tableHeaders = '';
        foreach ($columns as $c) {
            $tableHeaders .= "<th>" . htmlspecialchars($c) . "</th>";
        }

        $tableBody = '';
        if (empty($rows)) {
            $colSpan = max(1, count($columns));
            $tableBody = "<tr><td colspan='{$colSpan}' class='text-muted text-center'>No records found in this table.</td></tr>";
        } else {
            foreach ($rows as $row) {
                $tableBody .= "<tr>";
                foreach ($columns as $c) {
                    $val = $row[$c] ?? null;
                    if ($val === null) {
                        $displayVal = '<span class="null-val">NULL</span>';
                    } elseif (is_string($val) && strlen($val) > 80) {
                        $displayVal = htmlspecialchars(substr($val, 0, 77)) . '...';
                    } else {
                        $displayVal = htmlspecialchars((string)$val);
                    }
                    $tableBody .= "<td>{$displayVal}</td>";
                }
                $tableBody .= "</tr>";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>⚡ LitePlatform — Database Studio</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090d16;
            --surface: #0f172a;
            --surface-elevated: #1e293b;
            --border: #334155;
            --accent: #38bdf8;
            --accent-hover: #0ea5e9;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --green: #4ade80;
            --font-main: 'Plus Jakarta Sans', system-ui, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-main);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        .sidebar {
            width: 260px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .brand {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand h1 { font-size: 1.1rem; font-weight: 700; color: var(--accent); }
        .brand span { font-size: 0.75rem; background: rgba(56, 189, 248, 0.15); color: var(--accent); padding: 0.2rem 0.5rem; border-radius: 4px; }
        .table-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }
        .table-nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 0.8rem;
            border-radius: 6px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            margin-bottom: 2px;
            transition: all 0.15s ease;
        }
        .table-nav-item:hover {
            background: var(--surface-elevated);
            color: var(--text);
        }
        .table-nav-item.active {
            background: rgba(56, 189, 248, 0.15);
            color: var(--accent);
            font-weight: 600;
        }
        .badge {
            font-size: 0.75rem;
            background: #1e293b;
            padding: 0.15rem 0.45rem;
            border-radius: 12px;
            font-family: var(--font-mono);
        }
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .topbar {
            height: 56px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
        }
        .tabs { display: flex; gap: 0.5rem; }
        .tab-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
            border-radius: 6px;
            font-family: var(--font-main);
            font-weight: 500;
        }
        .tab-btn.active { background: var(--surface-elevated); color: var(--text); font-weight: 600; }
        .content {
            flex: 1;
            overflow: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .data-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .card-header {
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .table-responsive {
            flex: 1;
            overflow: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        th, td {
            padding: 0.65rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: #0b1120;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .null-val { color: #64748b; font-style: italic; font-size: 0.75rem; }
        .sql-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        textarea {
            width: 100%;
            height: 90px;
            background: #090d16;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-family: var(--font-mono);
            font-size: 0.85rem;
            padding: 0.75rem;
            resize: vertical;
            outline: none;
        }
        textarea:focus { border-color: var(--accent); }
        .sql-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
        .btn {
            padding: 0.5rem 1rem;
            background: var(--accent);
            color: #090d16;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .btn:hover { background: var(--accent-hover); }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--border);
            font-size: 0.8rem;
        }
        .page-link {
            padding: 0.25rem 0.6rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text);
            text-decoration: none;
        }
        .page-link.disabled { opacity: 0.4; pointer-events: none; }
        .text-center { text-align: center; }
        .text-muted { color: var(--text-muted); }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <h1>⚡ LiteStudio</h1>
            <span>{$driver}</span>
        </div>
        <div class="table-list">
            {$tableItems}
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('explorer')">📁 Data Browser</button>
                <button class="tab-btn" onclick="switchTab('sql')">⚡ SQL Console</button>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted)">
                Active table: <strong style="color: var(--accent)">{$selectedTable}</strong>
            </div>
        </header>
        <div class="content" id="tab-explorer">
            <div class="data-card">
                <div class="card-header">
                    <div>
                        <strong>Table: {$selectedTable}</strong>
                        <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem">({$totalRows} total rows)</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>{$tableHeaders}</tr>
                        </thead>
                        <tbody>
                            {$tableBody}
                        </tbody>
                    </table>
                </div>
                <div class="pagination">
                    <span>Page {$page} of {$totalPages}</span>
                    <a href="/_studio?table={$selectedTable}&page=" . max(1, $page - 1) class="page-link" style="margin-left: 0.5rem">&laquo; Prev</a>
                    <a href="/_studio?table={$selectedTable}&page=" . min($totalPages, $page + 1) class="page-link">Next &raquo;</a>
                </div>
            </div>
        </div>

        <div class="content" id="tab-sql" style="display: none">
            <div class="sql-box">
                <textarea id="sqlInput" placeholder="Enter SQL query (e.g. SELECT * FROM products WHERE price > 50)...">SELECT * FROM {$selectedTable} LIMIT 20;</textarea>
                <div class="sql-actions">
                    <button class="btn" onclick="runSql()">Run Query (Ctrl+Enter)</button>
                </div>
            </div>
            <div class="data-card" id="sqlResultsCard" style="display: none">
                <div class="card-header" id="sqlMeta"></div>
                <div class="table-responsive">
                    <table id="sqlTable">
                        <thead id="sqlThead"></thead>
                        <tbody id="sqlTbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            if (tab === 'explorer') {
                document.getElementById('tab-explorer').style.display = 'flex';
                document.getElementById('tab-sql').style.display = 'none';
                event.target.classList.add('active');
            } else {
                document.getElementById('tab-explorer').style.display = 'none';
                document.getElementById('tab-sql').style.display = 'flex';
                event.target.classList.add('active');
            }
        }

        async function runSql() {
            const sql = document.getElementById('sqlInput').value.trim();
            if (!sql) return;

            const res = await fetch('/_studio/query', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sql })
            });

            const data = await res.json();
            const card = document.getElementById('sqlResultsCard');
            const meta = document.getElementById('sqlMeta');
            const thead = document.getElementById('sqlThead');
            const tbody = document.getElementById('sqlTbody');

            card.style.display = 'flex';

            meta.textContent = '';
            thead.textContent = '';
            tbody.textContent = '';

            if (!data.success) {
                const errSpan = document.createElement('span');
                errSpan.style.color = '#f87171';
                errSpan.textContent = 'Error: ' + String(data.error ?? 'Unknown error');
                meta.appendChild(errSpan);
                return;
            }

            if (data.is_select) {
                const infoSpan = document.createElement('span');
                infoSpan.textContent = 'Returned ' + String(data.count) + ' rows in ';
                const msStrong = document.createElement('strong');
                msStrong.textContent = String(data.elapsed_ms) + ' ms';
                infoSpan.appendChild(msStrong);
                meta.appendChild(infoSpan);

                const headRow = document.createElement('tr');
                data.columns.forEach(c => {
                    const th = document.createElement('th');
                    th.textContent = String(c);
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);

                data.rows.forEach(r => {
                    const tr = document.createElement('tr');
                    data.columns.forEach(c => {
                        const td = document.createElement('td');
                        if (r[c] === null || r[c] === undefined) {
                            const nullSpan = document.createElement('span');
                            nullSpan.className = 'null-val';
                            nullSpan.textContent = 'NULL';
                            td.appendChild(nullSpan);
                        } else {
                            td.textContent = String(r[c]);
                        }
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                });
            } else {
                const okSpan = document.createElement('span');
                okSpan.style.color = '#4ade80';
                okSpan.textContent = 'Query executed successfully. Affected rows: ' + String(data.affected_rows) + ' (' + String(data.elapsed_ms) + ' ms)';
                meta.appendChild(okSpan);
            }
        }

        document.getElementById('sqlInput').addEventListener('keydown', (e) => {
            if (e.ctrlKey && e.key === 'Enter') runSql();
        });
    </script>
</body>
</html>
HTML;
    }
}
