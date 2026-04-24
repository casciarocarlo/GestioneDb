<?php
require_once 'config.php';

if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php'); exit;
}

$current_page     = 'home';
$page_title       = 'Dashboard';
$page_heading     = 'Dashboard';
$page_description = 'Welcome back — manage your MySQL databases';

$selected_db = $_SESSION['selected_db'] ?? '';
$db = new Database($selected_db ?: null);

/**
 * POST handlers / Gestori richieste POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_database') {
        $selected_db = sanitize($_POST['database'] ?? '');
        $_SESSION['selected_db'] = $selected_db;
        redirect('index.php');
    }

    if ($action === 'create_database') {
        $db_name = sanitize($_POST['db_name'] ?? '');
        if ($db_name) {
            try {
                $db->createDatabase($db_name);
                showMessage("Database '$db_name' created successfully!", 'success');
            } catch (Exception $e) {
                showMessage("Error: " . $e->getMessage(), 'error');
            }
        }
        redirect('index.php');
    }

    if ($action === 'drop_database') {
        $db_name = sanitize($_POST['db_name'] ?? '');
        $protected = ['information_schema','mysql','performance_schema','sys'];
        if ($db_name && !in_array($db_name, $protected)) {
            try {
                $db->dropDatabase($db_name);
                if ($_SESSION['selected_db'] === $db_name) unset($_SESSION['selected_db']);
                showMessage("Database '$db_name' deleted.", 'success');
            } catch (Exception $e) {
                showMessage("Error: " . $e->getMessage(), 'error');
            }
        }
        redirect('index.php');
    }
}

/**
 * Data retrieval / Recupero dati
 */
$system_dbs = ['information_schema','mysql','performance_schema','sys'];
$all_databases  = $db->getDatabases();
$databases      = array_filter($all_databases, fn($d) => !in_array($d, $system_dbs));
$tables         = $selected_db ? $db->getTables() : [];

/**
 * Data for Charts / Dati per i Grafici
 */
$table_stats = [];
if ($selected_db) {
    try {
        $stmt = $db->query("
            SELECT TABLE_NAME as name, TABLE_ROWS as rows, 
                   ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ? 
            ORDER BY TABLE_ROWS DESC 
            LIMIT 10
        ", [$selected_db]);
        $table_stats = $stmt->fetchAll();
    } catch (Exception $e) {
        $table_stats = [];
    }
}

include 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Page Header / Intestazione Pagina -->
<div class="page-header">
    <div class="page-header-info">
        <h1 class="page-title">🏠 Dashboard</h1>
        <p class="page-description">Overview of your MySQL databases and quick actions.</p>
    </div>
    <?php if ($selected_db): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="tables.php" class="btn btn-sm btn-ghost">📊 Tables</a>
        <a href="data.php"   class="btn btn-sm btn-ghost">📋 Data</a>
        <a href="query.php"  class="btn btn-sm btn-primary">💻 Run Query</a>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Row / Riga Statistiche -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px,1fr));">
    <div class="stat-card primary">
        <span class="stat-number"><?= count($databases) ?></span>
        <span class="stat-label">Databases</span>
    </div>
    <div class="stat-card success">
        <span class="stat-number"><?= count($tables) ?></span>
        <span class="stat-label">Tables<?= $selected_db ? " in <em style='font-weight:400'>" . htmlspecialchars($selected_db) . "</em>" : '' ?></span>
    </div>
    <div class="stat-card info">
        <span class="stat-number"><?= count($all_databases) ?></span>
        <span class="stat-label">Total Schemas</span>
    </div>
    <div class="stat-card warning">
        <span class="stat-number"><?= $selected_db ? '✓' : '—' ?></span>
        <span class="stat-label">Active DB</span>
    </div>
</div>

<?php if ($selected_db && !empty($table_stats)): ?>
<!-- Dashboard Charts / Grafici Dashboard -->
<div class="grid grid-2 mb-4">
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">📊 Records Distribution</div>
                <div class="card-subtitle">Top 10 tables by number of records</div>
            </div>
        </div>
        <div style="height: 300px; position: relative;">
            <canvas id="recordsChart"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">💾 Storage Usage (MB)</div>
                <div class="card-subtitle">Physical size of tables including indexes</div>
            </div>
        </div>
        <div style="height: 300px; position: relative;">
            <canvas id="sizeChart"></canvas>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const stats = <?= json_encode($table_stats) ?>;
    const labels = stats.map(s => s.name);
    const rowData = stats.map(s => s.rows);
    const sizeData = stats.map(s => s.size_mb);

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(255,255,255,0.05)' },
                ticks: { color: '#64748b', font: { size: 10 } }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#64748b', font: { size: 10 } }
            }
        }
    };

    // Records Chart
    new Chart(document.getElementById('recordsChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Records',
                data: rowData,
                backgroundColor: 'rgba(99, 102, 241, 0.5)',
                borderColor: '#818cf8',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: commonOptions
    });

    // Size Chart
    new Chart(document.getElementById('sizeChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Size (MB)',
                data: sizeData,
                backgroundColor: 'rgba(16, 185, 129, 0.5)',
                borderColor: '#34d399',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: commonOptions
    });
});
</script>
<?php endif; ?>

<div class="grid grid-2">

    <!-- Database Management / Gestione Database -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">🗄️ Database Management</div>
                <div class="card-subtitle">Create or remove databases</div>
            </div>
        </div>

        <!-- Create Database / Crea Database -->
        <form method="POST" class="mb-3">
            <input type="hidden" name="action" value="create_database">
            <div class="form-group">
                <label class="form-label" for="db_name_input">Create New Database</label>
                <div class="d-flex gap-2">
                    <input type="text" name="db_name" id="db_name_input" class="form-input"
                           placeholder="my_new_database" required
                           pattern="[a-zA-Z0-9_]+" title="Letters, numbers and underscores only">
                    <button type="submit" class="btn btn-success btn-sm" style="white-space:nowrap">
                        ＋ Create
                    </button>
                </div>
            </div>
        </form>

        <hr class="separator">

        <!-- Database list / Elenco Database -->
        <h4 class="fw-600 mb-2" style="font-size:.85rem;color:var(--text-secondary)">
            Existing Databases (<?= count($databases) ?>)
        </h4>
        <?php if (count($databases) > 0): ?>
        <div class="table-container" style="margin:0">
            <table class="table" id="db-list-table">
                <thead>
                    <tr>
                        <th>Database Name</th>
                        <th style="width:120px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($databases as $database): ?>
                    <tr>
                        <td>
                            <span class="fw-500"><?= htmlspecialchars($database) ?></span>
                            <?php if ($database === $selected_db): ?>
                                <span class="badge badge-success" style="margin-left:.5rem">active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST"
                                  onsubmit="return confirm('Delete database \'<?= htmlspecialchars($database) ?>\'? This CANNOT be undone!')">
                                <input type="hidden" name="action"  value="drop_database">
                                <input type="hidden" name="db_name" value="<?= htmlspecialchars($database) ?>">
                                <button type="submit" class="btn btn-danger btn-xs" id="drop-<?= htmlspecialchars($database) ?>">
                                    🗑 Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <span class="empty-state-icon">🗄️</span>
            <div class="empty-state-title">No databases yet</div>
            <p class="empty-state-text">Use the form above to create your first database.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Current Database Overview / Panoramica Database Corrente -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">📂 Database Overview</div>
                <div class="card-subtitle">
                    <?= $selected_db ? htmlspecialchars($selected_db) : 'No database selected' ?>
                </div>
            </div>
            <?php if ($selected_db): ?>
            <a href="schema.php" class="btn btn-ghost btn-sm">🔗 Schema</a>
            <?php endif; ?>
        </div>

        <?php if ($selected_db): ?>
            <?php if (!empty($tables)): ?>
            <div class="table-container" style="margin:0">
                <table class="table" id="tables-overview">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th style="width:160px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                        <tr>
                            <td class="fw-500"><?= htmlspecialchars($table) ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="data.php?table=<?= urlencode($table) ?>"
                                       class="btn btn-xs btn-primary">View</a>
                                    <a href="tables.php?action=structure&table=<?= urlencode($table) ?>"
                                       class="btn btn-xs btn-ghost">Structure</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="empty-state-icon">📊</span>
                <div class="empty-state-title">No tables found</div>
                <p class="empty-state-text">
                    <a href="tables.php" style="color:var(--accent-primary)">Create your first table →</a>
                </p>
            </div>
            <?php endif; ?>

        <?php else: ?>
        <div class="alert alert-warning" style="margin:0">
            ⚠️ Select a database from the sidebar to view its contents.
        </div>
        <?php endif; ?>
    </div>

<?php include 'includes/footer.php'; ?>
