<?php
/**
 * Admin page for managing database backups
 * Only accessible to admin users
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

// Initialize variables
$backup_dir = __DIR__ . '/backups';
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$error = null;
$success = null;

// Ensure backup directory exists
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Function to list backups
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

// Function to delete backup
function deleteBackup($backup_file) {
    if (!file_exists($backup_file)) {
        return false;
    }
    
    return unlink($backup_file);
}

// Handle actions
switch ($action) {
    case 'create':
        // Create a new backup
        if (empty($_SESSION['selected_db'])) {
            $error = 'Please select a database first.';
        } else {
            $db = new Database($_SESSION['selected_db']);
            $timestamp = date('Y-m-d_H-i-s');
            $backup_file = $backup_dir . '/backup_' . $_SESSION['selected_db'] . '_' . $timestamp . '.sql';
            
            // Use mysqldump for better performance
            $dump_cmd = sprintf(
                'mysqldump --single-transaction --routines --triggers --events --hex-blob --host=%s --port=%s --user=%s %s 2>&1',
                escapeshellarg(DB_HOST),
                escapeshellarg(3306),
                escapeshellarg(DB_USER),
                escapeshellarg($_SESSION['selected_db'])
            );
            
            $output = [];
            $return_code = 0;
            exec($dump_cmd . ' > ' . escapeshellarg($backup_file), $output, $return_code);
            
            if ($return_code === 0 && file_exists($backup_file)) {
                $success = "Backup created successfully: " . basename($backup_file);
            } else {
                $error = 'Backup creation failed. Check server logs.';
            }
        }
        break;
        
    case 'delete':
        // Delete a backup
        if (empty($_POST['backup_file'])) {
            $error = 'Please select a backup to delete';
        } elseif (!file_exists($_POST['backup_file'])) {
            $error = 'Selected backup file does not exist';
        } else {
            if (deleteBackup($_POST['backup_file'])) {
                $success = 'Backup deleted successfully';
            } else {
                $error = 'Failed to delete backup';
            }
        }
        break;
}

$backups = listBackups($backup_dir);
$page_title = 'Backup Management';
$page_heading = 'Database Backups';
$page_description = 'Admin interface for managing database backups';

include 'includes/header.php';
?>

<div class="backup-container">
    <div class="page-header">
        <div class="page-header-info">
            <h1 class="page-title">💾 Database Backup</h1>
            <p class="page-description">Create, restore, and manage database backups safely</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="backup.php">
                <input type="hidden" name="action" value="create">
                <button type="submit" class="btn btn-success">
                    ➕ Create Backup
                </button>
            </form>
            <a href="index.php" class="btn btn-ghost">← Back to Dashboard</a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <strong>Error:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Success:</strong> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <!-- Backup Statistics -->
    <div class="stats-grid" style="margin-bottom: 1.5rem;">
        <div class="stat-card primary">
            <span class="stat-number"><?= count($backups) ?></span>
            <span class="stat-label">Total Backups</span>
        </div>
        <div class="stat-card info">
            <span class="stat-number">
                <?= array_sum(array_column($backups, 'size')) > 0 ? 
                    (array_sum(array_column($backups, 'size')) / 1024 / 1024) : 0 ?> MB
            </span>
            <span class="stat-label">Total Size</span>
        </div>
        <?php if (!empty($backups)): ?>
        <div class="stat-card success">
            <span class="stat-number">
                <?= date('M d, Y', $backups[0]['modified']) ?>
            </span>
            <span class="stat-label">Latest Backup</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Backup List -->
    <?php if (empty($backups)): ?>
    <div class="card empty-state">
        <div class="empty-state-icon">💾</div>
        <div class="empty-state-title">No Backups Found</div>
        <p class="empty-state-text">Create your first backup using the button above.</p>
    </div>
    <?php else: ?>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Backup File</th>
                    <th>Size</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td>
                        <code style="font-size: 0.8rem;"><?= htmlspecialchars(basename($backup['file'])) ?></code>
                    </td>
                    <td><?= round($backup['size'] / 1024, 2) ?> KB</td>
                    <td><?= $backup['date'] ?></td>
                    <td>
                        <form method="POST" action="backups.php" style="display:inline;" 
                              onsubmit="return confirm('Are you sure you want to delete this backup?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup['path']) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>