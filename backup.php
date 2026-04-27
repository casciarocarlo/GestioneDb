<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'backup';
$selected_db = $_SESSION['selected_db'] ?? '';
$db = new Database($selected_db);

/**
 * Simple logging function / Semplice funzione di logging
 */
function logBackupActivity($message, $type = 'info')
{
    $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . DIRECTORY_SEPARATOR . 'backup.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;

    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Handle backup creation / Gestione creazione backup
 */
if ($_POST['action'] ?? '' === 'create_backup') {
    $backup_db = sanitize($_POST['backup_db'] ?? '');
    $backup_type = sanitize($_POST['backup_type'] ?? 'full');
    $include_data = isset($_POST['include_data']);

    if ($backup_db) {
        try {
            $backup_file = createDatabaseBackup($backup_db, $backup_type, $include_data);
            logBackupActivity("Backup created for database: $backup_db");
            showMessage("Backup created successfully! File: $backup_file", 'success');
        } catch (Exception $e) {
            logBackupActivity("Backup failed for database: $backup_db - " . $e->getMessage(), 'error');
            showMessage("Error creating backup: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle restore from backup / Gestione ripristino da backup
 */
if ($_POST['action'] ?? '' === 'restore_backup' && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
    $restore_db = sanitize($_POST['restore_db'] ?? '');
    $backup_file = $_FILES['backup_file']['tmp_name'];

    if ($restore_db && $backup_file) {
        try {
            restoreDatabaseFromBackup($restore_db, $backup_file);
            logBackupActivity("Database restored: $restore_db");
            showMessage("Database restored successfully from backup!", 'success');
            redirect('backup.php');
        } catch (Exception $e) {
            logBackupActivity("Restore failed for database: $restore_db - " . $e->getMessage(), 'error');
            showMessage("Error restoring backup: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle backup file deletion / Gestione eliminazione file backup
 */
if ($_POST['action'] ?? '' === 'delete_backup') {
    $backup_file = sanitize($_POST['backup_file'] ?? '');
    $backup_path = getBackupPath() . DIRECTORY_SEPARATOR . $backup_file;

    if (file_exists($backup_path) && unlink($backup_path)) {
        logBackupActivity("Backup file deleted: $backup_file");
        showMessage("Backup file deleted successfully!", 'success');
    } else {
        showMessage("Error deleting backup file!", 'error');
    }
    redirect('backup.php');
}

$databases = $db->getDatabases();
$backup_files = getBackupFiles();

/**
 * Backup functions / Funzioni di backup
 */
function getBackupPath()
{
    $backup_dir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    return $backup_dir;
}

function getBackupFiles()
{
    $backup_path = getBackupPath();
    $files = [];

    if (is_dir($backup_path)) {
        $scan = scandir($backup_path);
        foreach ($scan as $file) {
            if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $files[] = [
                    'name' => $file,
                    'size' => filesize($backup_path . DIRECTORY_SEPARATOR . $file),
                    'date' => filemtime($backup_path . DIRECTORY_SEPARATOR . $file)
                ];
            }
        }

        // Sort by date descending
        usort($files, function ($a, $b) {
            return $b['date'] - $a['date'];
        });
    }

    return $files;
}

function createDatabaseBackup($database, $type = 'full', $include_data = true)
{
    global $db;

    $timestamp = date('Y-m-d_H-i-s');
    $filename = "backup_{$database}_{$timestamp}.sql";
    $backup_path = getBackupPath() . DIRECTORY_SEPARATOR . $filename;

    // Create database connection for the specific database
    $backup_db = new Database($database);
    $tables = $backup_db->getTables();

    $backup_content = "-- MySQL Database Backup\n";
    $backup_content .= "-- Database: {$database}\n";
    $backup_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $backup_content .= "-- Type: {$type}\n\n";
    $backup_content .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $backup_content .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $backup_content .= "SET AUTOCOMMIT=0;\n";
    $backup_content .= "START TRANSACTION;\n\n";

    // Create database
    $backup_content .= "CREATE DATABASE IF NOT EXISTS `{$database}`;\n";
    $backup_content .= "USE `{$database}`;\n\n";

    foreach ($tables as $table) {
        // Get table structure
        $create_table = $backup_db->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $backup_content .= "-- Table structure for `{$table}`\n";
        $backup_content .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $backup_content .= $create_table['Create Table'] . ";\n\n";

        // Get table data if requested
        if ($include_data) {
            $rows = $backup_db->query("SELECT * FROM `{$table}`")->fetchAll();
            if (!empty($rows)) {
                $backup_content .= "-- Data for table `{$table}`\n";

                // Get column names
                $columns = array_keys($rows[0]);
                $columns_str = '`' . implode('`, `', $columns) . '`';

                $backup_content .= "INSERT INTO `{$table}` ({$columns_str}) VALUES\n";

                $values = [];
                foreach ($rows as $row) {
                    $row_values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $row_values[] = 'NULL';
                        } else {
                            $row_values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = '(' . implode(', ', $row_values) . ')';
                }

                $backup_content .= implode(",\n", $values) . ";\n\n";
            }
        }
    }

    $backup_content .= "COMMIT;\n";
    $backup_content .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // Write backup file
    if (file_put_contents($backup_path, $backup_content) === false) {
        throw new Exception("Failed to write backup file");
    }

    return $filename;
}

function restoreDatabaseFromBackup($database, $backup_file)
{
    global $db;

    $sql_content = file_get_contents($backup_file);
    if ($sql_content === false) {
        throw new Exception("Failed to read backup file");
    }

    // Create database connection without specific database
    $restore_db = new Database();

    // Create database if it doesn't exist
    try {
        $restore_db->createDatabase($database);
    } catch (Exception $e) {
        // Database might already exist, continue
    }

    // Switch to the target database
    $restore_db = new Database($database);

    // Split SQL into individual queries
    $queries = preg_split('/;\s*$/m', $sql_content);

    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query) && !preg_match('/^--/', $query)) {
            try {
                $restore_db->query($query);
            } catch (Exception $e) {
                // Log error but continue with other queries
                error_log("Restore query error: " . $e->getMessage() . " - Query: " . substr($query, 0, 100));
            }
        }
    }
}

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

$page_title = 'Backup';
$page_heading = 'Backup';
include 'includes/header.php';
?>


<div class="grid grid-2">
    <!-- Create Backup / Crea Backup -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">💾 Create Backup</div>
                <div class="card-subtitle">Export database structure and data to a SQL file</div>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_backup">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

            <div class="form-group">
                <label class="form-label">Select Database</label>
                <select name="backup_db" class="form-select" required>
                    <option value="">-- Select Database --</option>
                    <?php foreach ($databases as $database): ?>
                        <?php if (!in_array($database, ['information_schema', 'mysql', 'performance_schema', 'sys'])): ?>
                            <option value="<?= sanitize($database) ?>" <?= $database === $selected_db ? 'selected' : '' ?>>
                                <?= sanitize($database) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Backup Type</label>
                <select name="backup_type" class="form-select">
                    <option value="full">Full Backup (Structure + Data)</option>
                    <option value="structure">Structure Only</option>
                </select>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="include_data" name="include_data" checked>
                    <label for="include_data">Include table data</label>
                </div>
            </div>

            <button type="submit" class="btn btn-success">
                💾 Create Backup
            </button>
        </form>
    </div>

    <!-- Restore Backup / Ripristina Backup -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">🔄 Restore from Backup</div>
                <div class="card-subtitle">Import a SQL file to restore or create a database</div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="restore_backup">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

            <div class="form-group">
                <label class="form-label">Target Database Name</label>
                <input type="text" name="restore_db" class="form-input" placeholder="Database name" required>
                <small class="form-text">Database will be created if it doesn't exist</small>
            </div>

            <div class="form-group">
                <label class="form-label">Backup File (.sql)</label>
                <input type="file" name="backup_file" class="form-input" accept=".sql" required>
            </div>

            <button type="submit" class="btn btn-warning"
                onclick="return confirm('This will overwrite existing data. Continue?')">
                🔄 Restore Database
            </button>
        </form>
    </div>
</div>

<!-- Existing Backups / Backup Esistenti -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">📂 Available Backups</div>
            <div class="card-subtitle">Manage your local SQL dump files</div>
        </div>
    </div>

    <?php if (!empty($backup_files)): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backup_files as $backup): ?>
                        <tr>
                            <td><?= sanitize($backup['name']) ?></td>
                            <td><?= formatFileSize($backup['size']) ?></td>
                            <td><?= date('Y-m-d H:i:s', $backup['date']) ?></td>
                            <td>
                                <a href="backups/<?= urlencode($backup['name']) ?>" class="btn btn-sm"
                                    download="<?= sanitize($backup['name']) ?>">
                                    📥 Download
                                </a>
                                <form method="POST" style="display: inline;"
                                    onsubmit="return confirm('Delete backup file <?= sanitize($backup['name']) ?>?')">
                                    <input type="hidden" name="action" value="delete_backup">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="backup_file" value="<?= sanitize($backup['name']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            No backup files found. Create your first backup to get started.
        </div>
    <?php endif; ?>
</div>

<!-- Backup Tips / Suggerimenti Backup -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">💡 Backup Tips</h3>
    </div>
    <div class="alert alert-info">
        <ul class="list-unstyled mb-0">
            <li><strong>Regular Backups:</strong> Schedule regular backups of important databases</li>
            <li><strong>Storage:</strong> Keep backups in a secure location outside the web directory</li>
            <li><strong>Testing:</strong> Regularly test backup restoration to ensure data integrity</li>
            <li><strong>Naming:</strong> Use descriptive names with timestamps for easy identification</li>
            <li><strong>Size:</strong> Large databases may take time to backup/restore</li>
        </ul>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Update backup type based on include_data checkbox
        const includeDataCheckbox = document.getElementById('include_data');
        const backupTypeSelect = document.querySelector('select[name="backup_type"]');

        if (includeDataCheckbox && backupTypeSelect) {
            includeDataCheckbox.addEventListener('change', function () {
                if (!this.checked) {
                    backupTypeSelect.value = 'structure';
                } else {
                    backupTypeSelect.value = 'full';
                }
            });

            backupTypeSelect.addEventListener('change', function () {
                if (this.value === 'structure') {
                    includeDataCheckbox.checked = false;
                } else {
                    includeDataCheckbox.checked = true;
                }
            });
        }

        // Add loading state for backup operations
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function () {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '⏳ Processing...';
                }
            });
        });
    });
</script>
<?php include 'includes/footer.php'; ?>