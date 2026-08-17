<?php
require_once 'config.php';

/**
 * Backup functionality for GestioneDb
 * Creates SQL dumps of databases for backup and recovery
 */

if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

// Check if user has admin privileges
if (!hasRole('admin')) {
    showMessage('Access denied. Administrator privileges required.', 'error');
    redirect('index.php');
}

$selected_db = $_SESSION['selected_db'] ?? '';
$backup_dir = __DIR__ . '/backups';
$backup_file = null;
$backup_size = 0;
$backup_time = null;
$error = null;

// Ensure backup directory exists
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

/**
 * Create a backup of the current database
 */
function createBackup($db, $backup_dir) {
    $db_name = $db->getCurrentDatabase();
    $timestamp = date('Y-m-d_H-i-s');
    $backup_file = $backup_dir . '/backup_' . $db_name . '_' . $timestamp . '.sql';
    
    // Start output buffering
    ob_start();
    
    try {
        // Set MySQL variables for backup
        $db->query("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
        $db->query("SET FOREIGN_KEY_CHECKS=0");
        
        // Get CREATE DATABASE statement
        $db->query("SHOW CREATE DATABASE `" . $db_name . "`");
        $create_db = $db->query("SHOW CREATE DATABASE `" . $db_name . "`");
        $create_db_stmt = $create_db->fetch();
        
        // Output CREATE DATABASE statement
        echo "-- GestioneDb Backup\n";
        echo "-- Database: " . $db_name . "\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        echo $create_db_stmt['Create Database'] . ";\n\n";
        
        // Get all tables
        $tables = $db->getTables();
        
        foreach ($tables as $table) {
            // Get table structure
            $structure = $db->getTableStructure($table);
            
            // Output CREATE TABLE statement
            echo "-- Table structure for table `" . $table . "`\n\n";
            echo "CREATE TABLE `" . $table . "` (\n";
            
            $columns = [];
            foreach ($structure as $column) {
                $columns[] = "  `" . $column['Field'] . "` " . $column['Type'] . 
                    (empty($column['Null']) ? ' NOT NULL' : ' NULL') . 
                    (empty($column['Key']) ? '' : ' ' . $column['Key']) . 
                    (empty($column['Default']) ? '' : ' DEFAULT ' . $column['Default']) . 
                    (empty($column['Extra']) ? '' : ' ' . $column['Extra']);
            }
            
            echo implode(",\n", $columns) . "\n);\n\n";
            
            // Output table data
            $data = $db->query("SELECT * FROM `" . $table . "`");
            $rows = $data->fetchAll();
            
            if (!empty($rows)) {
                echo "INSERT INTO `" . $table . "` VALUES\n";
                
                foreach ($rows as $index => $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    
                    echo "(" . implode(', ', $values) . ")" . 
                        ($index < count($rows) - 1 ? ",\n" : "\n");
                }
                echo ";\n\n";
            }
        }
        
        // Restore MySQL variables
        $db->query("SET FOREIGN_KEY_CHECKS=1");
        
        // Get the output and clear buffer
        $sql_content = ob_get_clean();
        
        // Write to file
        if (file_put_contents($backup_file, $sql_content) !== false) {
            return [
                'success' => true,
                'file' => $backup_file,
                'size' => strlen($sql_content),
                'tables' => count($tables),
                'rows' => count($tables)
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to write backup file'
            ];
        }
        
    } catch (Exception $e) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        return [
            'success' => false,
            'error' => 'Backup failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Validate backup file
 */
function validateBackupFile($backup_file) {
    if (!file_exists($backup_file)) {
        return false;
    }
    
    $content = file_get_contents($backup_file);
    
    // Basic validation: check if it looks like a SQL dump
    if (strpos($content, 'CREATE TABLE') === false) {
        return false;
    }
    
    return true;
}

/**
 * Restore a backup file
 */
function restoreBackup($db, $backup_file) {
    if (!validateBackupFile($backup_file)) {
        return [
            'success' => false,
            'error' => 'Invalid backup file'
        ];
    }
    
    try {
        // Read backup file
        $sql_content = file_get_contents($backup_file);
        
        // Split SQL statements
        $statements = explode(';', $sql_content);
        
        // Start transaction
        $db->query('START TRANSACTION');
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }
            
            // Execute statement
            $db->query($statement);
        }
        
        // Commit transaction
        $db->query('COMMIT');
        
        return [
            'success' => true,
            'message' => 'Backup restored successfully'
        ];
        
    } catch (Exception $e) {
        // Rollback on error
        try {
            $db->query('ROLLBACK');
        } catch (Exception $rollback_e) {
            // Ignore rollback errors
        }
        
        return [
            'success' => false,
            'error' => 'Restore failed: ' . $e->getMessage()
        ];
    }
}

/**
 * List backup files
 */
function listBackups($backup_dir) {
    if (!is_dir($backup_dir)) {
        return [];
    }
    
    $backups = [];
    $files = scandir($backup_dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $file_path = $backup_dir . '/' . $file;
        
        if (is_file($file_path) && strtolower(substr($file, -4)) === '.sql') {
            $backups[] = [
                'file' => $file,
                'path' => $file_path,
                'size' => filesize($file_path),
                'modified' => filemtime($file_path),
                'date' => date('Y-m-d H:i:s', filemtime($file_path))
            ];
        }
    }
    
    // Sort by modification time (newest first)
    usort($backups, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    
    return $backups;
}

/**
 * Delete a backup file
 */
function deleteBackup($backup_file) {
    if (!file_exists($backup_file)) {
        return false;
    }
    
    return unlink($backup_file);
}

// Main execution
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'create' && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['confirm']))) {
    if (empty($selected_db)) {
        showMessage('Please select a database first.', 'error');
        redirect('index.php');
    }
    
    $db = new Database($selected_db);
    $result = createBackup($db, $backup_dir);
    
    if ($result['success']) {
        $backup_file = $result['file'];
        $backup_size = $result['size'];
        $backup_time = date('Y-m-d H:i:s');
        
        showMessage("Backup created successfully! File: " . basename($backup_file), 'success');
    } else {
        $error = $result['error'];
        showMessage($error, 'error');
    }
    redirect('backup.php');
}

// Handle backup restoration
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $backup_file = $_POST['backup_file'] ?? '';
    
    if (empty($backup_file) || !file_exists($backup_file)) {
        showMessage('Invalid backup file selected.', 'error');
        redirect('backup.php');
    }
    
    if (empty($selected_db)) {
        showMessage('Please select a database first.', 'error');
        redirect('backup.php');
    }
    
    $db = new Database($selected_db);
    $result = restoreBackup($db, $backup_file);
    
    if ($result['success']) {
        showMessage($result['message'], 'success');
    } else {
        showMessage($result['error'], 'error');
    }
    
    redirect('backup.php');
}

// Handle backup deletion
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $backup_file = $_POST['backup_file'] ?? '';
    
    if (empty($backup_file) || !file_exists($backup_file)) {
        showMessage('Invalid backup file selected.', 'error');
        redirect('backup.php');
    }
    
    if (deleteBackup($backup_file)) {
        showMessage('Backup deleted successfully.', 'success');
    } else {
        showMessage('Failed to delete backup.', 'error');
    }
    
    redirect('backup.php');
}

// Get list of backups
$backups = listBackups($backup_dir);

$page_title = 'Database Backup';
$page_heading = 'Database Backup';
$page_description = 'Create, restore, and manage database backups';

include 'includes/header.php';
?>

<style>
.backup-container {
    max-width: 1200px;
    margin: 0 auto;
}

.backup-actions {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.backup-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    margin-bottom: 1rem;
    transition: all var(--transition-fast);
}

.backup-card:hover {
    border-color: var(--border-default);
    box-shadow: var(--shadow-md);
}

.backup-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.backup-info {
    flex: 1;
}

.backup-file-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.backup-meta {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
}

.backup-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.backup-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    text-align: center;
}

.stat-number {
    font-size: 2rem;
    font-weight: 800;
    color: var(--accent-primary);
    margin-bottom: 0.5rem;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--text-muted);
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-muted);
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.4;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: var(--bg-surface);
    border-radius: var(--radius-lg);
    padding: 2rem;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.modal-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-primary);
}

.modal-body {
    margin-bottom: 1.5rem;
}

.modal-footer {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.alert {
    padding: 1rem;
    border-radius: var(--radius-md);
    margin-bottom: 1rem;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: var(--color-success);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--color-danger);
}

.alert-warning {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: var(--color-warning);
}
</style>

<div class="backup-container">
    <div class="page-header">
        <div class="page-header-info">
            <h1 class="page-title">💾 Database Backup</h1>
            <p class="page-description">Create, restore, and manage database backups safely</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button onclick="showCreateBackupModal()" class="btn btn-success">
                ➕ Create Backup
            </button>
            <a href="index.php" class="btn btn-ghost">← Back to Dashboard</a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>Error:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Backup Statistics -->
    <div class="backup-stats">
        <div class="stat-card">
            <div class="stat-number"><?= count($backups) ?></div>
            <div class="stat-label">Total Backups</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">
                <?= array_sum(array_column($backups, 'size')) > 0 ? 
                    (array_sum(array_column($backups, 'size')) / 1024 / 1024) : 0 ?> MB
            </div>
            <div class="stat-label">Total Size</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">
                <?= !empty($backups) ? date('M d, Y', $backups[0]['modified']) : 'N/A' ?>
            </div>
            <div class="stat-label">Latest Backup</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">
                <?= !empty($backups) ? date('H:i:s', $backups[0]['modified']) : 'N/A' ?>
            </div>
            <div class="stat-label">Latest Time</div>
        </div>
    </div>

    <!-- Backup List -->
    <?php if (empty($backups)): ?>
    <div class="backup-card empty-state">
        <div class="empty-state-icon">💾</div>
        <div class="empty-state-title">No Backups Found</div>
        <p class="empty-state-text">Create your first backup using the button above.</p>
    </div>
    <?php else: ?>
    <?php foreach ($backups as $backup): ?>
    <div class="backup-card">
        <div class="backup-header">
            <div class="backup-info">
                <div class="backup-file-name">
                    📄 <?= htmlspecialchars(basename($backup['file'])) ?>
                </div>
                <div class="backup-meta">
                    📅 Created: <?= $backup['date'] ?>
                </div>
                <div class="backup-meta">
                    📊 Size: <?= round($backup['size'] / 1024, 2) ?> KB
                </div>
                <div class="backup-meta">
                    🗂️ Tables: Unknown
                </div>
            </div>
            <div class="backup-actions">
                <button onclick="restoreBackup('<?= htmlspecialchars($backup['path']) ?>')" 
                        class="btn btn-success btn-sm">
                    🔄 Restore
                </button>
                <button onclick="deleteBackup('<?= htmlspecialchars($backup['path']) ?>')" 
                        class="btn btn-danger btn-sm">
                    🗑️ Delete
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Create Backup Modal -->
<div id="createBackupModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Create Database Backup</h3>
            <button onclick="closeCreateBackupModal()" class="btn btn-ghost">✕</button>
        </div>
        <div class="modal-body">
            <p>Create a backup of the current database <strong><?= htmlspecialchars($selected_db) ?></strong></p>
            <p>The backup will be saved as a SQL file in the backups directory.</p>
            <p>All tables and data will be included in the backup.</p>
        </div>
        <div class="modal-footer">
            <button onclick="closeCreateBackupModal()" class="btn btn-ghost">Cancel</button>
            <button onclick="createBackup()" class="btn btn-success">Create Backup</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Delete Backup</h3>
            <button onclick="closeDeleteModal()" class="btn btn-ghost">✕</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this backup? This action cannot be undone.</p>
            <p class="text-danger">All data in this backup will be lost.</p>
        </div>
        <div class="modal-footer">
            <button onclick="closeDeleteModal()" class="btn btn-ghost">Cancel</button>
            <button id="confirmDeleteBtn" class="btn btn-danger">Delete Backup</button>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div id="restoreModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Restore Backup</h3>
            <button onclick="closeRestoreModal()" class="btn btn-ghost">✕</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to restore this backup?</p>
            <p class="text-warning">This will overwrite the current database and all existing data will be lost.</p>
            <p>Please select a database to restore to:</p>
            <select id="restoreDbSelect" class="form-select">
                <option value="">Select Database...</option>
                <?php
                $db = new Database('test_db');
                $databases = $db->getDatabases();
                foreach ($databases as $database): ?>
                <option value="<?= htmlspecialchars($database) ?>">
                    <?= htmlspecialchars($database) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="modal-footer">
            <button onclick="closeRestoreModal()" class="btn btn-ghost">Cancel</button>
            <button id="confirmRestoreBtn" class="btn btn-warning" disabled>Restore Backup</button>
        </div>
    </div>
</div>

<script>
let backupToDelete = null;
let backupToRestore = null;

function showCreateBackupModal() {
    document.getElementById('createBackupModal').classList.add('active');
}

function closeCreateBackupModal() {
    document.getElementById('createBackupModal').classList.remove('active');
}

function showDeleteModal(backupPath) {
    backupToDelete = backupPath;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    backupToDelete = null;
}

function showRestoreModal(backupPath) {
    backupToRestore = backupPath;
    document.getElementById('restoreModal').classList.add('active');
    
    // Enable restore button when database is selected
    document.getElementById('restoreDbSelect').addEventListener('change', function() {
        document.getElementById('confirmRestoreBtn').disabled = !this.value;
    });
}

function closeRestoreModal() {
    document.getElementById('restoreModal').classList.remove('active');
    backupToRestore = null;
    document.getElementById('restoreDbSelect').value = '';
    document.getElementById('confirmRestoreBtn').disabled = true;
}

function createBackup() {
    // Show loading state
    const btn = event.target.closest('.modal-footer').querySelector('.btn-success');
    const originalText = btn.textContent;
    btn.textContent = 'Creating...';
    btn.disabled = true;
    
    // Make AJAX request to create backup
    fetch('backup.php?action=create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error creating backup: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error creating backup: ' + error.message);
    })
    .finally(() => {
        btn.textContent = originalText;
        btn.disabled = false;
    });
}

function deleteBackup(backupPath) {
    showDeleteModal(backupPath);
    
    document.getElementById('confirmDeleteBtn').onclick = function() {
        // Make AJAX request to delete backup
        fetch('backup.php?action=delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ backup_file: backupToDelete })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Error deleting backup: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error deleting backup: ' + error.message);
        })
        .finally(() => {
            closeDeleteModal();
        });
    };
}

function restoreBackup(backupPath) {
    showRestoreModal(backupPath);
    
    document.getElementById('confirmRestoreBtn').onclick = function() {
        const dbName = document.getElementById('restoreDbSelect').value;
        if (!dbName) {
            alert('Please select a database');
            return;
        }
        
        // Make AJAX request to restore backup
        fetch('backup.php?action=restore', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                backup_file: backupToRestore,
                table: dbName 
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Error restoring backup: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error restoring backup: ' + error.message);
        })
        .finally(() => {
            closeRestoreModal();
        });
    };
}

// Show toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '2000';
    toast.style.animation = 'fadeIn 0.3s ease';
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    }, 3000);
}
</script>

<?php include 'includes/footer.php'; ?>