<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'logs';
$selected_db = $_SESSION['selected_db'] ?? '';
$db = new Database($selected_db);

/**
 * Simple logging system / Semplice sistema di logging
 */
function getLogFile($type = 'system')
{
    $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    return $log_dir . DIRECTORY_SEPARATOR . $type . '.log';
}

function writeLog($message, $type = 'system', $level = 'INFO')
{
    $log_file = getLogFile($type);
    $timestamp = date('Y-m-d H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db_name = $_SESSION['selected_db'] ?? 'none';

    $log_entry = "[$timestamp] [$level] [$type] [DB:$db_name] [IP:$user_ip] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function readLogFile($type = 'system', $lines = 100)
{
    $log_file = getLogFile($type);
    if (!file_exists($log_file)) {
        return [];
    }

    $content = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($content === false)
        return [];

    // Return last N lines
    return array_slice(array_reverse($content), 0, $lines);
}

function parseLogEntry($line)
{
    // Parse log format: [timestamp] [level] [type] [DB:name] [IP:address] message
    if (preg_match('/^\[([^\]]+)\] \[([^\]]+)\] \[([^\]]+)\] \[DB:([^\]]+)\] \[IP:([^\]]+)\] (.+)$/', $line, $matches)) {
        return [
            'timestamp' => $matches[1],
            'level' => $matches[2],
            'type' => $matches[3],
            'database' => $matches[4],
            'ip' => $matches[5],
            'message' => $matches[6]
        ];
    }

    return [
        'timestamp' => 'Unknown',
        'level' => 'INFO',
        'type' => 'system',
        'database' => 'unknown',
        'ip' => 'unknown',
        'message' => $line
    ];
}

/**
 * Handle log actions / Gestione azioni sui log
 */
if ($_POST['action'] ?? '' === 'clear_logs') {
    $log_type = sanitize($_POST['log_type'] ?? 'system');
    $log_file = getLogFile($log_type);
    if (file_exists($log_file)) {
        file_put_contents($log_file, '');
        writeLog("Logs cleared for type: $log_type", 'system', 'INFO');
        showMessage("Logs cleared successfully for type: $log_type", 'success');
    }
    redirect('logs.php');
}

// Log this page access
writeLog("Accessed logs page", 'system', 'INFO');

// Get log types and their files
$log_types = ['system', 'backup', 'query', 'error'];
$selected_log_type = $_GET['type'] ?? 'system';
$log_lines = (int) ($_GET['lines'] ?? 100);

// Validate log type
if (!in_array($selected_log_type, $log_types)) {
    $selected_log_type = 'system';
}

$log_entries = readLogFile($selected_log_type, $log_lines);
$parsed_logs = array_map('parseLogEntry', $log_entries);

// Get log statistics
function getLogStats()
{
    $stats = [];
    $log_types = ['system', 'backup', 'query', 'error'];

    foreach ($log_types as $type) {
        $log_file = getLogFile($type);
        if (file_exists($log_file)) {
            $stats[$type] = [
                'size' => filesize($log_file),
                'modified' => filemtime($log_file),
                'lines' => count(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ];
        } else {
            $stats[$type] = [
                'size' => 0,
                'modified' => 0,
                'lines' => 0
            ];
        }
    }

    return $stats;
}

$log_stats = getLogStats();

function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function getLevelBadgeClass($level)
{
    switch (strtoupper($level)) {
        case 'ERROR':
        case 'CRITICAL':
            return 'badge-danger';
        case 'WARNING':
            return 'badge-warning';
        case 'SUCCESS':
            return 'badge-success';
        case 'INFO':
        default:
            return 'badge-info';
    }
}


/**
 * Performance monitoring / Monitoraggio delle performance
 */
function getPerformanceStats()
{
    $stats = [
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
        'loaded_extensions' => count(get_loaded_extensions()),
        'mysql_version' => 'Unknown',
        'php_version' => PHP_VERSION
    ];

    try {
        global $db;
        $stmt = $db->query("SELECT VERSION() as version");
        $result = $stmt->fetch();
        $stats['mysql_version'] = $result['version'] ?? 'Unknown';
    } catch (Exception $e) {
        $stats['mysql_version'] = 'Connection Error';
    }

    return $stats;
}

$performance_stats = getPerformanceStats();
$page_title = 'Logs & Monitoring';
$page_heading = 'Logs & Monitoring';
include 'includes/header.php';
?>


<!-- Performance Overview / Panoramica Performance -->
<!-- Performance Overview / Panoramica Performance -->
<div class="stats-grid mb-4">
    <div class="stat-card primary">
        <div class="stat-number"><?= formatFileSize($performance_stats['memory_usage']) ?></div>
        <div class="stat-label">Memory Usage</div>
    </div>
    <div class="stat-card info">
        <div class="stat-number"><?= number_format($performance_stats['execution_time'] * 1000, 2) ?>ms</div>
        <div class="stat-label">Page Load Time</div>
    </div>
    <div class="stat-card success">
        <div class="stat-number"><?= $performance_stats['php_version'] ?></div>
        <div class="stat-label">PHP Version</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-number"><?= explode('-', $performance_stats['mysql_version'])[0] ?></div>
        <div class="stat-label">MySQL Version</div>
    </div>
</div>

<!-- Log Statistics / Statistiche Log -->
<div class="card mb-4">
    <div class="card-header">
        <div>
            <div class="card-title">📈 Log Statistics</div>
            <div class="card-subtitle">Overview of system and query log file sizes</div>
        </div>
    </div>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Log Type</th>
                    <th>File Size</th>
                    <th>Entries</th>
                    <th>Last Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_stats as $type => $stat): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= ucfirst($type) ?>
                            </strong>
                            <?php if ($type === $selected_log_type): ?>
                                <span class="badge badge-info">Viewing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= formatFileSize($stat['size']) ?>
                        </td>
                        <td>
                            <?= number_format($stat['lines']) ?>
                        </td>
                        <td>
                            <?php if ($stat['modified'] > 0): ?>
                                <?= date('Y-m-d H:i:s', $stat['modified']) ?>
                            <?php else: ?>
                                <em class="text-muted">No file</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="logs.php?type=<?= urlencode($type) ?>&lines=<?= $log_lines ?>"
                                class="btn btn-sm">View</a>
                            <?php if ($stat['size'] > 0): ?>
                                <form method="POST" style="display: inline;"
                                    onsubmit="return confirm('Clear all <?= $type ?> logs?')">
                                    <input type="hidden" name="action" value="clear_logs">
                                    <input type="hidden" name="log_type" value="<?= sanitize($type) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Clear</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Log Viewer / Visualizzatore Log -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">📄 Log Viewer: <?= ucfirst($selected_log_type) ?></div>
            <div class="card-subtitle">Showing last <?= $log_lines ?> lines from <?= $selected_log_type ?> log</div>
        </div>
    </div>

    <!-- Log Controls -->
    <div class="d-flex gap-2 mb-3" style="padding: 1rem; border-bottom: 1px solid #dee2e6;">
        <form method="GET" class="d-flex gap-2 align-center">
            <label class="form-label" style="margin: 0;">Type:</label>
            <select name="type" class="form-select" style="width: auto;" onchange="this.form.submit()">
                <?php foreach ($log_types as $type): ?>
                    <option value="<?= sanitize($type) ?>" <?= $type === $selected_log_type ? 'selected' : '' ?>>
                        <?= ucfirst($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="form-label" style="margin: 0;">Lines:</label>
            <select name="lines" class="form-select" style="width: auto;" onchange="this.form.submit()">
                <option value="50" <?= $log_lines == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $log_lines == 100 ? 'selected' : '' ?>>100</option>
                <option value="200" <?= $log_lines == 200 ? 'selected' : '' ?>>200</option>
                <option value="500" <?= $log_lines == 500 ? 'selected' : '' ?>>500</option>
            </select>

            <button type="button" onclick="location.reload()" class="btn btn-ghost">🔄 Refresh</button>
        </form>
    </div>

    <!-- Log Entries / Voci del Log -->
    <div style="max-height: 600px; overflow-y: auto; padding: 1rem;">
        <?php if (empty($parsed_logs)): ?>
            <div class="alert alert-info">
                No log entries found for
                <?= ucfirst($selected_log_type) ?> logs.
            </div>
        <?php else: ?>
            <?php foreach ($parsed_logs as $log): ?>
                <div class="log-entry <?= strtolower($log['level']) ?>">
                    <div class="d-flex justify-between align-center mb-1">
                        <div>
                            <span class="badge <?= getLevelBadgeClass($log['level']) ?>">
                                <?= sanitize($log['level']) ?>
                            </span>
                            <small class="text-muted">
                                <?= sanitize($log['timestamp']) ?>
                            </small>
                            <small class="text-muted">DB:
                                <?= sanitize($log['database']) ?>
                            </small>
                            <small class="text-muted">IP:
                                <?= sanitize($log['ip']) ?>
                            </small>
                        </div>
                    </div>
                    <div style="font-weight: normal;">
                        <?= sanitize($log['message']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions / Azioni Rapide -->
<div class="card mt-4">
    <div class="card-header">
        <div>
            <div class="card-title">⚡ Quick Actions</div>
            <div class="card-subtitle">Refresh, download or filter current log data</div>
        </div>
    </div>
    <div class="d-flex gap-2 p-3">
        <button onclick="downloadLogs()" class="btn btn-ghost">📥 Download Logs</button>
        <button onclick="startAutoRefresh()" class="btn btn-ghost" id="autoRefreshBtn">🔄 Auto Refresh</button>
        <button onclick="showFilterModal()" class="btn btn-ghost">🔍 Filter</button>
    </div>
</div>

<script>
    let autoRefreshInterval = null;

    function downloadLogs() {
        const type = '<?= sanitize($selected_log_type) ?>';
        const content = document.querySelector('.card:last-of-type').previousElementSibling.querySelector('div[style*="max-height"]').innerText;

        const blob = new Blob([content], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${type}_logs_${new Date().toISOString().slice(0, 10)}.txt`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    function startAutoRefresh() {
        const btn = document.getElementById('autoRefreshBtn');

        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
            btn.textContent = '🔄 Auto Refresh';
            btn.classList.remove('btn-success');
            btn.classList.add('btn-ghost');
        } else {
            autoRefreshInterval = setInterval(() => {
                location.reload();
            }, 10000); // Refresh every 10 seconds

            btn.textContent = '⏸️ Stop Auto Refresh';
            btn.classList.remove('btn-ghost');
            btn.classList.add('btn-success');
        }
    }

    function showFilterModal() {
        const filter = prompt('Enter text to filter logs (case-insensitive):');
        if (!filter) return;

        const logEntries = document.querySelectorAll('.log-entry');
        logEntries.forEach(entry => {
            const text = entry.textContent.toLowerCase();
            if (text.includes(filter.toLowerCase())) {
                entry.style.display = 'block';
            } else {
                entry.style.display = 'none';
            }
        });
    }

    // Auto-scroll to bottom if viewing recent logs
    document.addEventListener('DOMContentLoaded', function () {
        const logContainer = document.querySelector('div[style*="max-height"]');
        if (logContainer && <?= $log_lines <= 100 ? 'true' : 'false' ?>) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    });
</script>
<?php include 'includes/footer.php'; ?>