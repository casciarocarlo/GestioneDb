<?php
/**
 * Performance Monitoring Dashboard for GestioneDb
 * Displays real-time database performance metrics
 */

require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

// Require admin privileges
if (!hasRole('admin')) {
    showMessage('Access denied. Administrator privileges required.', 'error');
    redirect('index.php');
}

$selected_db = $_SESSION['selected_db'] ?? '';
if (!$selected_db) {
    showMessage('Please select a database first.', 'warning');
    redirect('index.php');
}

$db = new Database($selected_db);

/**
 * Get slow query log entries
 */
function getSlowQueries($db, $limit = 50) {
    try {
        // Try to get from performance_schema if available
        $stmt = $db->query("
            SELECT 
                DIGEST_TEXT as query,
                COUNT_STAR as executions,
                AVG_TIMER_WAIT/1000000000 as avg_time_ms,
                SUM_ROWS_EXAMINED as rows_examined,
                SUM_ROWS_SENT as rows_sent
            FROM performance_schema.events_statements_summary_by_digest 
            WHERE SCHEMA_NAME = ?
            ORDER BY AVG_TIMER_WAIT DESC 
            LIMIT ?
        ", [$db->getCurrentDatabase(), $limit]);
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        // Fallback: read from slow query log file
        return getSlowQueryLogEntries($limit);
    }
}

/**
 * Read slow query log entries from file
 */
function getSlowQueryLogEntries($limit = 50) {
    $log_file = __DIR__ . '/logs/slow.log';
    if (!file_exists($log_file)) {
        return [];
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $entries = [];
    
    foreach (array_slice(array_reverse($lines), 0, $limit * 10) as $line) {
        if (preg_match('/^# User@Host:.*?Id: (\d+).*?Time: (\d+\.\d+)/', $line, $matches)) {
            $entries[] = [
                'id' => $matches[1],
                'time' => $matches[2],
                'query' => ''
            ];
        }
    }
    
    return array_slice($entries, 0, $limit);
}

/**
 * Get table statistics
 */
function getTableStats($db) {
    try {
        $stmt = $db->query("
            SELECT 
                TABLE_NAME as name,
                TABLE_ROWS as rows,
                ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb,
                TABLE_COLLATION as collation
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_ROWS DESC
        ", [$db->getCurrentDatabase()]);
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get database size statistics
 */
function getDatabaseSize($db) {
    try {
        $stmt = $db->query("
            SELECT 
                table_schema as database_name,
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb,
                COUNT(*) as tables
            FROM information_schema.TABLES 
            WHERE table_schema = ?
            GROUP BY table_schema
        ", [$db->getCurrentDatabase()]);
        
        return $stmt->fetch();
    } catch (Exception $e) {
        return ['size_mb' => 0, 'tables' => 0];
    }
}

/**
 * Get connection statistics
 */
function getConnectionStats($db) {
    try {
        $stmt = $db->query("
            SHOW STATUS WHERE Variable_name IN (
                'Threads_connected',
                'Threads_running',
                'Threads_waiting',
                'Connections',
                'Max_used_connections',
                'Aborted_connects',
                'Aborted_clients'
            )
        ");
        
        $stats = [];
        while ($row = $stmt->fetch()) {
            $stats[$row['Variable_name']] = $row['Value'];
        }
        
        return $stats;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get query execution statistics
 */
function getQueryStats($db) {
    try {
        $stmt = $db->query("
            SHOW STATUS WHERE Variable_name IN (
                'Questions',
                'Queries',
                'Com_select',
                'Com_insert',
                'Com_update',
                'Com_delete',
                'Com_create_table',
                'Com_drop_table',
                'Select_scan',
                'Select_full_join',
                'Created_tmp_tables',
                'Created_tmp_disk_tables',
                'Handler_read_rnd_next'
            )
        ");
        
        $stats = [];
        while ($row = $stmt->fetch()) {
            $stats[$row['Variable_name']] = $row['Value'];
        }
        
        return $stats;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get system resource usage
 */
function getSystemResources() {
    $resources = [];
    
    // CPU Load
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $resources['cpu_load'] = [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2]
        ];
    }
    
    // Memory usage
    $resources['memory'] = [
        'used' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
        'limit' => ini_get('memory_limit')
    ];
    
    // Disk usage
    $resources['disk'] = [
        'total' => disk_total_space(__DIR__),
        'free' => disk_free_space(__DIR__),
        'used' => disk_total_space(__DIR__) - disk_free_space(__DIR__)
    ];
    
    // PHP version
    $resources['php_version'] = PHP_VERSION;
    
    return $resources;
}

/**
 * Get table with most reads/writes
 */
function getTableActivity($db, $limit = 10) {
    try {
        // This requires performance_schema to be enabled
        $stmt = $db->query("
            SELECT 
                OBJECT_NAME as table_name,
                COUNT_READ as reads,
                COUNT_WRITE as writes,
                COUNT_READ + COUNT_WRITE as total
            FROM performance_schema.table_io_waits_summary_by_table 
            WHERE OBJECT_SCHEMA = ?
            ORDER BY total DESC
            LIMIT ?
        ", [$db->getCurrentDatabase(), $limit]);
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        // Fallback: use table stats
        $tables = getTableStats($db);
        return array_slice($tables, 0, $limit);
    }
}

/**
 * Check for potential issues
 */
function checkDatabaseHealth($db) {
    $issues = [];
    
    // Check for tables without primary keys
    try {
        $stmt = $db->query("
            SELECT TABLE_NAME 
            FROM information_schema.TABLES t
            LEFT JOIN (
                SELECT TABLE_NAME 
                FROM information_schema.STATISTICS 
                WHERE TABLE_SCHEMA = ? AND INDEX_NAME = 'PRIMARY'
            ) p ON t.TABLE_NAME = p.TABLE_NAME
            WHERE t.TABLE_SCHEMA = ? AND p.TABLE_NAME IS NULL
        ", [$db->getCurrentDatabase(), $db->getCurrentDatabase()]);
        
        $tables_without_pk = $stmt->fetchAll();
        if (!empty($tables_without_pk)) {
            $issues[] = [
                'severity' => 'warning',
                'message' => count($tables_without_pk) . ' tables without primary keys',
                'details' => implode(', ', array_column($tables_without_pk, 'TABLE_NAME'))
            ];
        }
    } catch (Exception $e) {
        // Ignore
    }
    
    // Check for fragmented tables
    try {
        $stmt = $db->query("
            SELECT TABLE_NAME, DATA_FREE 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ? AND DATA_FREE > 0
            ORDER BY DATA_FREE DESC
        ", [$db->getCurrentDatabase()]);
        
        $fragmented = $stmt->fetchAll();
        if (!empty($fragmented)) {
            $issues[] = [
                'severity' => 'info',
                'message' => count($fragmented) . ' tables may be fragmented',
                'details' => 'Consider running OPTIMIZE TABLE'
            ];
        }
    } catch (Exception $e) {
        // Ignore
    }
    
    // Check for tables with too many rows
    try {
        $stmt = $db->query("
            SELECT TABLE_NAME, TABLE_ROWS 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_ROWS > 1000000
            ORDER BY TABLE_ROWS DESC
        ", [$db->getCurrentDatabase()]);
        
        $large_tables = $stmt->fetchAll();
        if (!empty($large_tables)) {
            $issues[] = [
                'severity' => 'info',
                'message' => count($large_tables) . ' large tables detected (>1M rows)',
                'details' => implode(', ', array_column($large_tables, 'TABLE_NAME'))
            ];
        }
    } catch (Exception $e) {
        // Ignore
    }
    
    return $issues;
}

// Get all metrics
$slow_queries = getSlowQueries($db, 20);
$table_stats = getTableStats($db);
$db_size = getDatabaseSize($db);
$conn_stats = getConnectionStats($db);
$query_stats = getQueryStats($db);
$system_resources = getSystemResources();
$table_activity = getTableActivity($db, 10);
$health_issues = checkDatabaseHealth($db);

// Calculate performance metrics
$total_queries = $query_stats['Queries'] ?? 0;
$select_queries = $query_stats['Com_select'] ?? 0;
$insert_queries = $query_stats['Com_insert'] ?? 0;
$update_queries = $query_stats['Com_update'] ?? 0;
$delete_queries = $query_stats['Com_delete'] ?? 0;

// Calculate query distribution
$query_distribution = [
    'SELECT' => (int)$select_queries,
    'INSERT' => (int)$insert_queries,
    'UPDATE' => (int)$update_queries,
    'DELETE' => (int)$delete_queries
];

// Calculate connection stats
$threads_connected = $conn_stats['Threads_connected'] ?? 0;
$max_connections = $conn_stats['Max_used_connections'] ?? 0;
$aborted_connects = $conn_stats['Aborted_connects'] ?? 0;

// Calculate disk usage percentage
$disk_total = $system_resources['disk']['total'] ?? 1;
$disk_used = $system_resources['disk']['used'] ?? 0;
$disk_percent = $disk_total > 0 ? round(($disk_used / $disk_total) * 100, 1) : 0;

// Calculate memory usage percentage
$memory_limit = $system_resources['memory']['limit'] ?? '128M';
$memory_limit_bytes = return_bytes($memory_limit);
$memory_used = $system_resources['memory']['used'] ?? 0;
$memory_percent = $memory_limit_bytes > 0 ? round(($memory_used / $memory_limit_bytes) * 100, 1) : 0;

/**
 * Convert shorthand byte values to bytes
 */
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

$page_title = 'Performance Monitor';
$page_heading = 'Performance Monitor';
$page_description = 'Real-time database performance metrics and health checks';

include 'includes/header.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="monitor-container">
    <div class="page-header">
        <div class="page-header-info">
            <h1 class="page-title">📊 Performance Monitor</h1>
            <p class="page-description">Real-time database performance metrics and health checks for <strong><?= htmlspecialchars($selected_db) ?></strong></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button onclick="refreshMetrics()" class="btn btn-ghost btn-sm">
                🔄 Refresh
            </button>
            <a href="index.php" class="btn btn-ghost btn-sm">← Dashboard</a>
        </div>
    </div>

    <!-- Health Issues -->
    <?php if (!empty($health_issues)): ?>
    <div class="card mb-4" style="border-left: 4px solid var(--color-warning);">
        <div class="card-header">
            <div class="card-title">⚠️ Health Check Issues</div>
            <div class="card-subtitle">Potential issues detected in your database</div>
        </div>
        <div class="card-body">
            <?php foreach ($health_issues as $issue): ?>
            <div class="alert alert-<?= $issue['severity'] === 'warning' ? 'warning' : ($issue['severity'] === 'error' ? 'danger' : 'info') ?> mb-2">
                <strong><?= htmlspecialchars($issue['message']) ?></strong>
                <?php if (!empty($issue['details'])): ?>
                    <br><small><?= htmlspecialchars($issue['details']) ?></small>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- System Resources -->
    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card primary">
            <span class="stat-number"><?= $disk_percent ?>%</span>
            <span class="stat-label">Disk Usage</span>
            <div class="stat-change">
                <small><?= round($disk_used / 1024 / 1024 / 1024, 2) ?> GB / <?= round($disk_total / 1024 / 1024 / 1024, 2) ?> GB</small>
            </div>
        </div>
        <div class="stat-card info">
            <span class="stat-number"><?= $memory_percent ?>%</span>
            <span class="stat-label">Memory Usage</span>
            <div class="stat-change">
                <small><?= round($memory_used / 1024 / 1024, 2) ?> MB / <?= $memory_limit ?></small>
            </div>
        </div>
        <div class="stat-card success">
            <span class="stat-number"><?= $threads_connected ?></span>
            <span class="stat-label">Active Connections</span>
            <div class="stat-change">
                <small>Max: <?= $max_connections ?></small>
            </div>
        </div>
        <div class="stat-card warning">
            <span class="stat-number"><?= $aborted_connects ?></span>
            <span class="stat-label">Aborted Connections</span>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-2 mb-4">
        <!-- Query Distribution -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">📈 Query Distribution</div>
                    <div class="card-subtitle">Query types executed</div>
                </div>
            </div>
            <div style="height: 250px; position: relative;">
                <canvas id="queryDistributionChart"></canvas>
            </div>
        </div>

        <!-- Table Sizes -->
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">💾 Table Sizes (MB)</div>
                    <div class="card-subtitle">Database storage breakdown</div>
                </div>
            </div>
            <div style="height: 250px; position: relative;">
                <canvas id="tableSizeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Slow Queries -->
    <div class="card mb-4">
        <div class="card-header">
            <div>
                <div class="card-title">🐢 Slow Queries</div>
                <div class="card-subtitle">Top queries by average execution time</div>
            </div>
        </div>
        
        <?php if (!empty($slow_queries)): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Query</th>
                        <th>Executions</th>
                        <th>Avg Time (ms)</th>
                        <th>Rows Examined</th>
                        <th>Rows Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slow_queries as $index => $query): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td>
                            <code style="font-size: 0.75rem;">
                                <?= htmlspecialchars(substr($query['query'] ?? 'N/A', 0, 100)) ?>
                                <?= strlen($query['query'] ?? '') > 100 ? '...' : '' ?>
                            </code>
                        </td>
                        <td><?= htmlspecialchars($query['executions'] ?? 0) ?></td>
                        <td><?= htmlspecialchars(round($query['avg_time_ms'] ?? 0, 2)) ?></td>
                        <td><?= htmlspecialchars($query['rows_examined'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($query['rows_sent'] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            No slow queries detected. Performance is optimal!
        </div>
        <?php endif; ?>
    </div>

    <!-- Table Statistics -->
    <div class="card mb-4">
        <div class="card-header">
            <div>
                <div class="card-title">📊 Table Statistics</div>
                <div class="card-subtitle">Database tables overview</div>
            </div>
        </div>
        
        <?php if (!empty($table_stats)): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Table Name</th>
                        <th>Rows</th>
                        <th>Size (MB)</th>
                        <th>Collation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_stats as $table): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($table['name']) ?></code></td>
                        <td><?= number_format($table['rows']) ?></td>
                        <td><?= htmlspecialchars($table['size_mb']) ?></td>
                        <td><?= htmlspecialchars($table['collation']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            No tables found in the selected database.
        </div>
        <?php endif; ?>
    </div>

    <!-- Database Summary -->
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">📋 Database Summary</div>
                    <div class="card-subtitle">Overview of current database</div>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex justify-between align-center mb-3">
                    <span>Database Name</span>
                    <strong><?= htmlspecialchars($selected_db) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>Total Tables</span>
                    <strong><?= count($table_stats) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>Total Size</span>
                    <strong><?= htmlspecialchars($db_size['size_mb'] ?? 0) ?> MB</strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>Total Rows</span>
                    <strong><?= number_format(array_sum(array_column($table_stats, 'rows'))) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>PHP Version</span>
                    <strong><?= htmlspecialchars($system_resources['php_version']) ?></strong>
                </div>
                <div class="d-flex justify-between align-center">
                    <span>Server Load (1min)</span>
                    <strong><?= isset($system_resources['cpu_load']['1min']) ? htmlspecialchars($system_resources['cpu_load']['1min']) : 'N/A' ?></strong>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <div class="card-title">⚡ Query Statistics</div>
                    <div class="card-subtitle">Query execution metrics</div>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex justify-between align-center mb-3">
                    <span>Total Queries</span>
                    <strong><?= number_format($total_queries) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>SELECT Queries</span>
                    <strong style="color: var(--accent-blue);"><?= number_format($select_queries) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>INSERT Queries</span>
                    <strong style="color: var(--color-success);"><?= number_format($insert_queries) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>UPDATE Queries</span>
                    <strong style="color: var(--color-warning);"><?= number_format($update_queries) ?></strong>
                </div>
                <div class="d-flex justify-between align-center mb-3">
                    <span>DELETE Queries</span>
                    <strong style="color: var(--color-danger);"><?= number_format($delete_queries) ?></strong>
                </div>
                <div class="d-flex justify-between align-center">
                    <span>Temp Tables Created</span>
                    <strong><?= number_format($query_stats['Created_tmp_tables'] ?? 0) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Query Distribution Chart
const queryCtx = document.getElementById('queryDistributionChart').getContext('2d');
new Chart(queryCtx, {
    type: 'doughnut',
    data: {
        labels: ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        datasets: [{
            data: [
                <?= $select_queries ?: 0 ?>,
                <?= $insert_queries ?: 0 ?>,
                <?= $update_queries ?: 0 ?>,
                <?= $delete_queries ?: 0 ?>
            ],
            backgroundColor: [
                'rgba(96, 165, 250, 0.7)',
                'rgba(16, 185, 129, 0.7)',
                'rgba(251, 191, 36, 0.7)',
                'rgba(248, 113, 113, 0.7)'
            ],
            borderColor: [
                '#60a5fa',
                '#10b981',
                '#fbbf24',
                '#f87171'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'right',
                labels: {
                    color: '#94a3b8',
                    font: { size: 11 }
                }
            }
        }
    }
});

// Table Size Chart
const sizeCtx = document.getElementById('tableSizeChart').getContext('2d');
new Chart(sizeCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(function($t) { return $t['name']; }, array_slice($table_stats, 0, 10))) ?: '[]' ?>,
        datasets: [{
            label: 'Size (MB)',
            data: <?= json_encode(array_map(function($t) { return $t['size_mb']; }, array_slice($table_stats, 0, 10))) ?: '[]' ?>,
            backgroundColor: 'rgba(139, 92, 246, 0.7)',
            borderColor: '#8b5cf6',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            x: {
                grid: { color: 'rgba(255,255,255,0.05)' },
                ticks: { color: '#64748b', font: { size: 10 } }
            },
            y: {
                grid: { display: false },
                ticks: { color: '#64748b', font: { size: 10 } }
            }
        }
    }
});

// Auto-refresh metrics every 30 seconds
function refreshMetrics() {
    window.location.reload();
}

// Auto-refresh every 60 seconds
setTimeout(function() {
    window.location.reload();
}, 60000);
</script>

<?php include 'includes/footer.php'; ?>