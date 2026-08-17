<?php
/**
 * Shared Header & Sidebar Component
 * Include this file at the top of every authenticated page.
 *
 * Required variables (set before including):
 *   $page_title      — page <title> tag text
 *   $current_page    — nav link active state key
 *   $page_heading    — topbar left heading (optional)
 *   $page_description — topbar subtitle (optional)
 */

// Ensure auth is loaded
if (!function_exists('isAuthenticated')) {
    require_once dirname(__DIR__) . '/config.php';
}

$user         = getCurrentUser();
$user_initials = strtoupper(substr($user['username'] ?? 'U', 0, 2));
$selected_db  = $_SESSION['selected_db'] ?? '';
$page_heading = $page_heading ?? ($page_title ?? 'Dashboard');
$page_description = $page_description ?? '';

// Load user's saved connections from auth DB
$sidebar_connections = [];
try {
    $auth_pdo = new PDO(
        "mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME . ";charset=utf8mb4",
        AUTH_DB_USER, AUTH_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // table may not exist yet on first boot
    $chk = $auth_pdo->query("SHOW TABLES LIKE 'user_connections'");
    if ($chk && $chk->rowCount() > 0) {
        $uid_h = (int)($_SESSION['user_id'] ?? 0);
        $s = $auth_pdo->prepare("SELECT id, label, driver, db_name FROM user_connections WHERE user_id=? ORDER BY label");
        $s->execute([$uid_h]);
        $sidebar_connections = $s->fetchAll();
    }
} catch (Exception $e) {
    // silently fail
}

$active_conn_id = $_SESSION['active_connection_id'] ?? null;

$nav_items = [
    ['key'=>'home',        'href'=>'index.php',       'icon'=>'🏠', 'label'=>__('dashboard')],
    ['key'=>'connections', 'href'=>'connections.php',  'icon'=>'🔌', 'label'=>__('connections', 'Connections')],
    ['key'=>'tables',      'href'=>'tables.php',      'icon'=>'📊', 'label'=>__('tables')],
    ['key'=>'data',        'href'=>'data.php',        'icon'=>'📋', 'label'=>__('data')],
    ['key'=>'query',       'href'=>'query.php',       'icon'=>'💻', 'label'=>__('query')],
    ['key'=>'builder',     'href'=>'builder.php',     'icon'=>'🏗️', 'label'=>__('query_builder', 'Query Builder')],
    ['key'=>'schema',      'href'=>'schema.php',      'icon'=>'🔗', 'label'=>__('schema_viewer', 'Schema Viewer')],
    ['key'=>'procedures',  'href'=>'procedures.php',  'icon'=>'⚙️', 'label'=>__('stored_procedures', 'Stored Procedures')],
    ['key'=>'backup',      'href'=>'backup.php',      'icon'=>'💾', 'label'=>__('backup')],
    ['key'=>'monitor',     'href'=>'monitor.php',     'icon'=>'📊', 'label'=>__('monitor', 'Monitor')],
    ['key'=>'export',      'href'=>'export.php',      'icon'=>'📤', 'label'=>__('export_import', 'Export / Import')],
    ['key'=>'logs',        'href'=>'logs.php',        'icon'=>'📝', 'label'=>__('logs')],
];

if (hasRole('admin')) {
    $nav_items[] = ['key'=>'users', 'href'=>'users.php', 'icon'=>'👥', 'label'=>__('user_management', 'User Management')];
}
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['lang'] ?? 'en' ?>" data-theme="<?= htmlspecialchars($_SESSION['theme_preference'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="GestioneDb — Professional MySQL Database Manager">
    <title><?= htmlspecialchars($page_title ?? 'Dashboard') ?> — GestioneDb</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&family=Roboto:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="includes/ui/ui-kit.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🗄️</text></svg>">
    <script>
        // Init theme immediately to prevent FOUC
        const savedTheme = localStorage.getItem('gestionedb_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
</head>
<body>
<div class="app-wrapper">

    <!-- ═══════════════════════════════════════════════
         SIDEBAR
    ═══════════════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">

        <!-- Brand -->
        <a href="index.php" class="sidebar-brand" title="Go to Dashboard">
            <span class="sidebar-brand-icon">🗄️</span>
            <span class="sidebar-brand-text">
                <span class="sidebar-brand-name">GestioneDb</span>
                <span class="sidebar-brand-version">v<?= defined('APP_VERSION') ? APP_VERSION : '2.0.0' ?></span>
            </span>
        </a>

        <!-- User Connections -->
        <div class="sidebar-db-selector">
            <label><?= __('connections', 'My Connections') ?></label>
            <?php if (empty($sidebar_connections)): ?>
                <a href="connections.php" class="btn btn-xs btn-ghost" style="width:100%; margin-top:.4rem; text-align:center;">+ <?= __('add_connection', 'Add Connection') ?></a>
            <?php else: ?>
            <?php
                $driverColors = ['mysql'=>'#4479A1','pgsql'=>'#336791','sqlsrv'=>'#CC2927','sqlite'=>'#003B57'];
                $driverIcons  = ['mysql'=>'🐬','pgsql'=>'🐘','sqlsrv'=>'🪟','sqlite'=>'📁'];
            ?>
            <div style="display:flex;flex-direction:column;gap:0.35rem;margin-top:.5rem;">
                <?php foreach ($sidebar_connections as $sc): ?>
                <?php
                    $isConn = $active_conn_id == $sc['id'];
                    $dc = $driverColors[$sc['driver']] ?? '#888';
                    $di = $driverIcons[$sc['driver']] ?? '🗄️';
                ?>
                <form method="POST" action="connections.php">
                    <input type="hidden" name="action" value="activate_connection">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                    <input type="hidden" name="conn_id" value="<?= $sc['id'] ?>">
                    <button type="submit" style="
                        width:100%; text-align:left; background: <?= $isConn ? 'rgba(99,102,241,.18)' : 'transparent' ?>;
                        border: 1px solid <?= $isConn ? 'var(--accent-primary)' : 'var(--border)' ?>;
                        border-radius: var(--radius); padding:.35rem .6rem;
                        cursor:pointer; color:var(--text-primary); font-size:.72rem;
                        display:flex; align-items:center; gap:.4rem;
                    ">
                        <span><?= $di ?></span>
                        <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($sc['label']) ?></span>
                        <?php if ($isConn): ?><span style="color:var(--success); font-size:.6rem;">●</span><?php endif; ?>
                    </button>
                </form>
                <?php endforeach; ?>
                <a href="connections.php" style="font-size:.7rem; opacity:.6; text-align:center; display:block; margin-top:.2rem;">⚙ <?= __('manage_connections', 'Manage') ?></a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Search -->
        <div class="sidebar-search">
            <input type="text" id="nav-search" placeholder="Search navigation..." onkeyup="filterNav()">
        </div>

        <!-- Navigation -->
        <nav class="sidebar-nav" id="sidebar-nav" aria-label="Page navigation">
            <span class="nav-section-label">Navigation</span>
            <?php foreach ($nav_items as $item): ?>
            <div class="nav-item">
                <a href="<?= $item['href'] ?>"
                   class="nav-link <?= $current_page === $item['key'] ? 'active' : '' ?>"
                   id="nav-<?= $item['key'] ?>"
                   data-label="<?= strtolower($item['label']) ?>"
                   aria-current="<?= $current_page === $item['key'] ? 'page' : 'false' ?>">
                    <span class="nav-link-icon"><?= $item['icon'] ?></span>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            </div>
            <?php endforeach; ?>
        </nav>

        <script>
            function filterNav() {
                const query = document.getElementById('nav-search').value.toLowerCase();
                document.querySelectorAll('.nav-link').forEach(link => {
                    const label = link.getAttribute('data-label');
                    link.parentElement.style.display = label.includes(query) ? '' : 'none';
                });
            }
        </script>

        <!-- User / Logout -->
        <div class="sidebar-footer">
            <a href="profile.php" style="display:flex;align-items:center;gap:.6rem;text-decoration:none;flex:1;min-width:0;" title="<?= __('profile','My Profile') ?>">
                <div class="sidebar-user-avatar" style="cursor:pointer;"><?= $user_initials ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars($user['username']) ?></div>
                    <div class="sidebar-user-role"><?= htmlspecialchars($user['role']) ?></div>
                </div>
            </a>
            <a href="logout.php" class="sidebar-logout" title="<?= __('logout','Logout') ?>" aria-label="Logout">⏻</a>
        </div>
    </aside>

    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

    <!-- ═══════════════════════════════════════════════
         MAIN AREA
    ═══════════════════════════════════════════════ -->
    <div class="main-area">

        <!-- Top Bar -->
        <header class="topbar" role="banner">
            <div class="d-flex align-center gap-3">
                <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="false">
                    ☰
                </button>
                <div>
                    <div class="topbar-title"><?= htmlspecialchars($page_heading) ?></div>
                    <?php if ($page_description): ?>
                    <div class="topbar-breadcrumb"><?= htmlspecialchars($page_description) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="topbar-actions">
                <button id="theme-toggle" class="btn btn-ghost" title="Toggle Theme" style="padding: 0.3rem 0.6rem; border-radius: var(--radius-full);">
                    <svg class="icon-dark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                    <svg class="icon-light" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                </button>

                <!-- Language Switcher -->
                <div class="lang-switcher" style="display: flex; gap: 0.25rem;">
                    <?php $current_lang = $_SESSION['lang'] ?? 'en'; ?>
                    <a href="?lang=en" class="btn btn-xs <?= $current_lang === 'en' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">EN</a>
                    <a href="?lang=it" class="btn btn-xs <?= $current_lang === 'it' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">IT</a>
                    <a href="?lang=fr" class="btn btn-xs <?= $current_lang === 'fr' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">FR</a>
                    <a href="?lang=es" class="btn btn-xs <?= $current_lang === 'es' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">ES</a>
                </div>
                <?php
                $driverIcons = ['mysql'=>'🐬','pgsql'=>'🐘','sqlsrv'=>'🪟','sqlite'=>'📁'];
                $activeDriver = $_SESSION['connection_driver'] ?? 'mysql';
                $activeLabel  = $_SESSION['active_connection_label'] ?? '';
                $activeDbIcon = $driverIcons[$activeDriver] ?? '🗄️';
                ?>
                <?php if ($selected_db): ?>
                    <a href="connections.php" class="badge badge-primary" id="topbar-active-db" style="text-decoration:none; cursor:pointer;" title="<?= __('manage_connections','Manage Connections') ?>">
                        <?= $activeDbIcon ?>
                        <?php if ($activeLabel): ?>
                            <span style="opacity:.75; font-weight:400;"><?= htmlspecialchars($activeLabel) ?> /</span>
                        <?php endif; ?>
                        <?= htmlspecialchars($selected_db) ?>
                    </a>
                <?php else: ?>
                    <a href="connections.php" class="badge badge-muted" style="text-decoration:none; cursor:pointer;">
                        🔌 <?= __('add_connection','Add Connection') ?>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Page Content starts here -->
        <main class="page-content" id="main-content" role="main">
            <?php
            // Display flash messages
            if (isset($_SESSION['message'])) {
                $msg_type  = $_SESSION['message_type'] ?? 'info';
                $alert_cls = match($msg_type) {
                    'success' => 'alert-success',
                    'error'   => 'alert-danger',
                    'warning' => 'alert-warning',
                    default   => 'alert-info',
                };
                $icons = ['success'=>'✅','error'=>'❌','warning'=>'⚠️','info'=>'ℹ️'];
                $icon  = $icons[$msg_type] ?? 'ℹ️';
                echo '<div class="alert ' . $alert_cls . ' alert-dismissible" role="alert">';
                echo '<span>' . $icon . '</span>';
                echo '<span>' . htmlspecialchars($_SESSION['message']) . '</span>';
                echo '<button type="button" class="btn-close" onclick="this.parentElement.remove()" aria-label="Close">✕</button>';
                echo '</div>';
                unset($_SESSION['message'], $_SESSION['message_type']);
            }
            ?>
