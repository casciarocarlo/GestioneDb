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

// Try to load database list for sidebar selector
$sidebar_databases = [];
try {
    $tmp_db = new Database('');
    $all_dbs = $tmp_db->getDatabases();
    $system_dbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
    $sidebar_databases = array_filter($all_dbs, fn($d) => !in_array($d, $system_dbs));
} catch (Exception $e) {
    // silently fail — DB list not critical for page load
}

$nav_items = [
    ['key'=>'home',       'href'=>'index.php',      'icon'=>'🏠', 'label'=>__('dashboard')],
    ['key'=>'tables',     'href'=>'tables.php',     'icon'=>'📊', 'label'=>__('tables')],
    ['key'=>'data',       'href'=>'data.php',       'icon'=>'📋', 'label'=>__('data')],
    ['key'=>'query',      'href'=>'query.php',      'icon'=>'💻', 'label'=>__('query')],
    ['key'=>'builder',    'href'=>'builder.php',    'icon'=>'🏗️', 'label'=>__('query_builder', 'Query Builder')],
    ['key'=>'schema',     'href'=>'schema.php',     'icon'=>'🔗', 'label'=>__('schema_viewer', 'Schema Viewer')],
    ['key'=>'procedures', 'href'=>'procedures.php', 'icon'=>'⚙️', 'label'=>__('stored_procedures', 'Stored Procedures')],
    ['key'=>'backup',     'href'=>'backup.php',     'icon'=>'💾', 'label'=>__('backup')],
    ['key'=>'export',     'href'=>'export.php',     'icon'=>'📤', 'label'=>__('export_import', 'Export / Import')],
    ['key'=>'logs',       'href'=>'logs.php',       'icon'=>'📝', 'label'=>__('logs')],
];

if (hasRole('admin')) {
    $nav_items[] = ['key'=>'users', 'href'=>'users.php', 'icon'=>'👥', 'label'=>__('user_management', 'User Management')];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="GestioneDb — Professional MySQL Database Manager">
    <title><?= htmlspecialchars($page_title ?? 'Dashboard') ?> — GestioneDb</title>
    <link rel="stylesheet" href="assets/css/style.css">
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

        <!-- Database Selector -->
        <div class="sidebar-db-selector">
            <label for="sidebar-db-select"><?= __('select_database') ?></label>
            <div class="sidebar-search" style="margin: 0.5rem 0; padding: 0;">
                <input type="text" id="db-search" placeholder="Filter databases..." 
                       style="padding: 6px 10px 6px 30px; font-size: 0.7rem;" onkeyup="filterDbs()">
            </div>
            <form method="POST" action="index.php" id="sidebar-db-form">
                <input type="hidden" name="action" value="select_database">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                <select name="database" id="sidebar-db-select" size="1" onchange="document.getElementById('sidebar-db-form').submit()">
                    <option value="">— Select Database —</option>
                    <?php foreach ($sidebar_databases as $sidebar_db): ?>
                        <option value="<?= htmlspecialchars($sidebar_db) ?>" <?= $sidebar_db === $selected_db ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sidebar_db) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <script>
            function filterDbs() {
                const query = document.getElementById('db-search').value.toLowerCase();
                const select = document.getElementById('sidebar-db-select');
                const options = select.options;
                
                for (let i = 1; i < options.length; i++) {
                    const text = options[i].text.toLowerCase();
                    const match = text.includes(query);
                    options[i].style.display = match ? '' : 'none';
                }
            }
        </script>

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
            <div class="sidebar-user-avatar"><?= $user_initials ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($user['username']) ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($user['role']) ?></div>
            </div>
            <a href="logout.php" class="sidebar-logout" title="Logout" aria-label="Logout">⏻</a>
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
                    <a href="?lang=en" class="btn btn-xs <?= $_SESSION['lang'] === 'en' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">EN</a>
                    <a href="?lang=it" class="btn btn-xs <?= $_SESSION['lang'] === 'it' ? 'btn-primary' : 'btn-ghost' ?>" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">IT</a>
                </div>
                <?php if ($selected_db): ?>
                    <span class="badge badge-primary" id="topbar-active-db">
                        🗃️ <?= htmlspecialchars($selected_db) ?>
                    </span>
                <?php else: ?>
                    <span class="badge badge-muted">No DB selected</span>
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
