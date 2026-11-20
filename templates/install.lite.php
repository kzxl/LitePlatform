<!DOCTYPE html>
<html lang="{{ $locale ?? 'vi' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ($locale ?? 'vi') === 'vi' ? 'Cài đặt LitePlatform' : 'LitePlatform Setup Wizard' }}</title>
    <style>
        :root {
            --bg: #f6f8fa;
            --surface: #ffffff;
            --border: #d0d7de;
            --text: #1f2328;
            --muted: #656d76;
            --accent: #0969da;
            --accent-hover: #0550ae;
            --ok-bg: #dafbe1;
            --ok-text: #116329;
            --warn-bg: #fff8c5;
            --warn-text: #9a6700;
            --err-bg: #ffebe9;
            --err-text: #cf222e;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.55;
            padding: 2rem 1rem;
        }
        .wrap { max-width: 720px; margin: 0 auto; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.25rem;
        }
        .brand { font-size: 1.2rem; font-weight: 800; letter-spacing: -0.02em; }
        .lang-pills a {
            display: inline-block; padding: 0.25rem 0.6rem; font-size: 0.78rem; font-weight: 600;
            border: 1px solid var(--border); border-radius: 6px; text-decoration: none; color: var(--muted);
            margin-left: 0.25rem; background: var(--surface);
        }
        .lang-pills a.active { background: var(--accent); color: #fff; border-color: var(--accent); }
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px; padding: 1.75rem; margin-bottom: 1.25rem;
            box-shadow: 0 1px 2px rgba(31, 35, 40, 0.04);
        }
        h1 { font-size: 1.45rem; font-weight: 700; margin-bottom: 0.35rem; }
        h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 0.85rem; }
        .subtitle { color: var(--muted); font-size: 0.92rem; margin-bottom: 1.25rem; }
        .check-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0.55rem; }
        .check-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 0.75rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem;
        }
        .pill { padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .pill-pass { background: var(--ok-bg); color: var(--ok-text); }
        .pill-warn { background: var(--warn-bg); color: var(--warn-text); }
        .pill-fail { background: var(--err-bg); color: var(--err-text); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.9rem; }
        .field { display: flex; flex-direction: column; gap: 0.35rem; }
        .field-full { grid-column: 1 / -1; }
        label { font-size: 0.84rem; font-weight: 600; color: var(--text); }
        input, select {
            width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border);
            border-radius: 6px; font-size: 0.92rem; background: #fff; color: var(--text);
        }
        input:focus, select:focus { outline: 2px solid rgba(9, 105, 218, 0.25); border-color: var(--accent); }
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.88rem; }
        .alert-err { background: var(--err-bg); color: var(--err-text); border: 1px solid rgba(207, 34, 46, 0.25); }
        .alert-ok { background: var(--ok-bg); color: var(--ok-text); border: 1px solid rgba(17, 99, 41, 0.25); }
        .actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-top: 1.25rem; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 0.65rem 1.25rem; border-radius: 6px; font-size: 0.92rem; font-weight: 600;
            border: 1px solid var(--border); cursor: pointer; text-decoration: none;
            background: var(--surface); color: var(--text);
        }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .db-status { font-size: 0.85rem; font-weight: 600; }
        .divider { height: 1px; background: var(--border); margin: 1.35rem 0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="brand">LitePlatform Setup</div>
        <div class="lang-pills">
            <a href="/install?lang=vi" class="{{ ($locale ?? 'vi') === 'vi' ? 'active' : '' }}">VI</a>
            <a href="/install?lang=en" class="{{ ($locale ?? 'vi') === 'en' ? 'active' : '' }}">EN</a>
        </div>
    </div>

    @if($completed ?? false)
        <div class="card">
            <div class="alert alert-ok">
                {{ ($locale ?? 'vi') === 'vi'
                    ? 'Cài đặt hoàn tất! Tệp .env chuẩn production đã được tạo và trình cài đặt /install đã tự động khóa vĩnh viễn.'
                    : 'Installation complete! Production .env generated and /install has been permanently locked.' }}
            </div>
            <h1>{{ ($locale ?? 'vi') === 'vi' ? 'Website của bạn đã sẵn sàng' : 'Your Website Is Ready' }}</h1>
            <p class="subtitle">
                {{ ($locale ?? 'vi') === 'vi'
                    ? 'Tài khoản quản trị, cơ sở dữ liệu, bài viết chào mừng và 3 khóa bảo mật ngẫu nhiên đã được khởi tạo.'
                    : 'Your admin account, database schema, welcome article, and cryptographic secrets have been initialized.' }}
            </p>
            <div class="actions">
                <a href="/" class="btn btn-primary">{{ ($locale ?? 'vi') === 'vi' ? 'Xem trang chủ' : 'Visit Website' }}</a>
                <a href="/admin/login" class="btn">{{ ($locale ?? 'vi') === 'vi' ? 'Đăng nhập Quản trị' : 'Admin Login' }}</a>
            </div>
        </div>
    @else
        <!-- Step 1: Preflight -->
        <div class="card">
            <h1>{{ ($locale ?? 'vi') === 'vi' ? 'Cài đặt nhanh trong 60 giây' : '60-Second Web Setup' }}</h1>
            <p class="subtitle">
                {{ ($locale ?? 'vi') === 'vi'
                    ? 'Tự động kiểm tra hosting, tạo .env bảo mật, chạy migration và khởi tạo tài khoản quản trị — không cần SSH.'
                    : 'Automatically checks hosting readiness, writes a hardened .env, runs migrations, and creates your admin account — zero SSH.' }}
            </p>

            <h2>1. {{ ($locale ?? 'vi') === 'vi' ? 'Kiểm tra hệ thống' : 'System Preflight' }}</h2>
            <div class="check-grid">
                @foreach($preflight['items'] as $item)
                <div class="check-row">
                    <span><strong>{{ $item['name'] }}</strong> <span style="color:var(--muted);">({{ $item['detail'] }})</span></span>
                    <span class="pill pill-{{ $item['status'] }}">{{ $item['status'] }}</span>
                </div>
                @endforeach
            </div>
        </div>

        <!-- Step 2 & 3: Database + Site + Admin -->
        <div class="card">
            @if(!empty($errors))
            <div class="alert alert-err">
                <ul style="padding-left: 1.2rem;">
                    @foreach($errors as $err)
                    <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="/install?lang={{ $locale ?? 'vi' }}" id="install-form">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale ?? 'vi' }}">

                <h2>2. {{ ($locale ?? 'vi') === 'vi' ? 'Kết nối cơ sở dữ liệu' : 'Database Connection' }}</h2>
                <div class="form-grid">
                    <div class="field">
                        <label for="db_driver">{{ ($locale ?? 'vi') === 'vi' ? 'Loại DB' : 'Driver' }}</label>
                        <select id="db_driver" name="db_driver">
                            <option value="mysql" {{ ($old['db_driver'] ?? 'mysql') === 'mysql' ? 'selected' : '' }}>MySQL / MariaDB (Shared Hosting)</option>
                            <option value="sqlite" {{ ($old['db_driver'] ?? '') === 'sqlite' ? 'selected' : '' }}>SQLite (Local / Micro-site)</option>
                        </select>
                    </div>
                    <div class="field mysql-only">
                        <label for="db_host">Host</label>
                        <input type="text" id="db_host" name="db_host" value="{{ $old['db_host'] ?? '127.0.0.1' }}">
                    </div>
                    <div class="field mysql-only">
                        <label for="db_port">Port</label>
                        <input type="number" id="db_port" name="db_port" value="{{ $old['db_port'] ?? '3306' }}">
                    </div>
                    <div class="field">
                        <label for="db_database">{{ ($locale ?? 'vi') === 'vi' ? 'Tên Database / Đường dẫn SQLite' : 'Database Name / SQLite Path' }}</label>
                        <input type="text" id="db_database" name="db_database" value="{{ $old['db_database'] ?? '' }}" placeholder="my_database">
                    </div>
                    <div class="field mysql-only">
                        <label for="db_username">Username</label>
                        <input type="text" id="db_username" name="db_username" value="{{ $old['db_username'] ?? '' }}">
                    </div>
                    <div class="field mysql-only">
                        <label for="db_password">Password</label>
                        <input type="password" id="db_password" name="db_password" value="">
                    </div>
                </div>

                <div class="actions" style="margin-top: 0.9rem;">
                    <button type="button" class="btn" id="btn-test-db">
                        {{ ($locale ?? 'vi') === 'vi' ? 'Kiểm tra kết nối DB' : 'Test DB Connection' }}
                    </button>
                    <span id="db-test-status" class="db-status"></span>
                </div>

                <div class="divider"></div>

                <h2>3. {{ ($locale ?? 'vi') === 'vi' ? 'Thông tin Website & Tài khoản Quản trị' : 'Site Info & Administrator' }}</h2>
                <div class="form-grid">
                    <div class="field">
                        <label for="site_name">{{ ($locale ?? 'vi') === 'vi' ? 'Tên Website' : 'Site Name' }}</label>
                        <input type="text" id="site_name" name="site_name" value="{{ $old['site_name'] ?? 'My Website' }}" required>
                    </div>
                    <div class="field">
                        <label for="site_url">Site URL</label>
                        <input type="url" id="site_url" name="site_url" value="{{ $old['site_url'] ?? $detectedUrl }}" required>
                    </div>
                    <div class="field field-full">
                        <label for="site_tagline">{{ ($locale ?? 'vi') === 'vi' ? 'Mô tả ngắn (Tagline SEO)' : 'Tagline / Short Description' }}</label>
                        <input type="text" id="site_tagline" name="site_tagline" value="{{ $old['site_tagline'] ?? '' }}" placeholder="{{ ($locale ?? 'vi') === 'vi' ? 'Giải pháp dịch vụ chuyên nghiệp...' : 'Modern, fast web platform...' }}">
                    </div>
                    <div class="field">
                        <label for="hotline">Hotline ({{ ($locale ?? 'vi') === 'vi' ? 'tùy chọn' : 'optional' }})</label>
                        <input type="text" id="hotline" name="hotline" value="{{ $old['hotline'] ?? '' }}" placeholder="0909 123 456">
                    </div>
                    <div class="field">
                        <label for="admin_name">{{ ($locale ?? 'vi') === 'vi' ? 'Tên Quản trị viên' : 'Admin Name' }}</label>
                        <input type="text" id="admin_name" name="admin_name" value="{{ $old['admin_name'] ?? 'Administrator' }}" required>
                    </div>
                    <div class="field">
                        <label for="admin_email">{{ ($locale ?? 'vi') === 'vi' ? 'Email đăng nhập Admin' : 'Admin Email' }}</label>
                        <input type="email" id="admin_email" name="admin_email" value="{{ $old['admin_email'] ?? '' }}" placeholder="admin@example.com" required>
                    </div>
                    <div class="field">
                        <label for="admin_password">{{ ($locale ?? 'vi') === 'vi' ? 'Mật khẩu Admin (≥ 8 ký tự)' : 'Admin Password (min 8 chars)' }}</label>
                        <input type="password" id="admin_password" name="admin_password" minlength="8" required>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary" {{ !$preflight['ok'] ? 'disabled' : '' }}>
                        {{ ($locale ?? 'vi') === 'vi' ? 'Cài đặt Website ngay' : 'Install Website Now' }}
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>

<script>
(function () {
    const driver = document.getElementById('db_driver');
    const dbInput = document.getElementById('db_database');
    const testBtn = document.getElementById('btn-test-db');
    const statusEl = document.getElementById('db-test-status');
    const form = document.getElementById('install-form');
    if (!driver) return;

    function toggleFields() {
        const isSqlite = driver.value === 'sqlite';
        document.querySelectorAll('.mysql-only').forEach(el => {
            el.style.display = isSqlite ? 'none' : 'flex';
        });
        if (isSqlite && !dbInput.value) {
            dbInput.value = 'storage/app.sqlite';
        }
    }
    driver.addEventListener('change', toggleFields);
    toggleFields();

    if (testBtn && form) {
        testBtn.addEventListener('click', async () => {
            statusEl.textContent = '...';
            statusEl.style.color = 'var(--muted)';
            const data = new FormData(form);
            try {
                const res = await fetch('/install/test-db', { method: 'POST', body: data });
                const json = await res.json();
                statusEl.textContent = json.message || (json.ok ? 'OK' : 'Error');
                statusEl.style.color = json.ok ? 'var(--ok-text)' : 'var(--err-text)';
            } catch (e) {
                statusEl.textContent = 'Request failed';
                statusEl.style.color = 'var(--err-text)';
            }
        });
    }
})();
</script>
</body>
</html>
