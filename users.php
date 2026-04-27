<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

/**
 * Require admin privileges / Richiede privilegi di amministratore
 */
requireAdmin();

$current_page = 'users';

/**
 * Database for authentication / Connessione al database di autenticazione
 */
function getAuthDB()
{
    try {
        $pdo = new PDO("mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME, AUTH_DB_USER, AUTH_DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Handle user management actions / Gestione azioni di amministrazione utenti
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pdo = getAuthDB();

    if ($action === 'toggle_status') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $is_active = (int) ($_POST['is_active'] ?? 0);

        if ($user_id > 0 && $user_id !== $_SESSION['user_id']) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->execute([$is_active ? 0 : 1, $user_id]);

                $status = $is_active ? 'deactivated' : 'activated';
                showMessage("User $status successfully!", 'success');

                // Log action
                $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
                if (!is_dir($log_dir))
                    mkdir($log_dir, 0755, true);
                $log_entry = "[" . date('Y-m-d H:i:s') . "] [INFO] [users] [DB:none] [IP:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "] User $status by admin: " . $_SESSION['username'] . PHP_EOL;
                file_put_contents($log_dir . DIRECTORY_SEPARATOR . 'system.log', $log_entry, FILE_APPEND | LOCK_EX);

            } catch (PDOException $e) {
                showMessage("Error updating user status!", 'error');
            }
        }
        redirect('users.php');
    } elseif ($action === 'change_role') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $new_role = $_POST['role'] ?? '';

        if ($user_id > 0 && $user_id !== $_SESSION['user_id'] && in_array($new_role, ['user', 'admin'])) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$new_role, $user_id]);

                showMessage("User role updated to $new_role!", 'success');

                // Log action
                $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
                if (!is_dir($log_dir))
                    mkdir($log_dir, 0755, true);
                $log_entry = "[" . date('Y-m-d H:i:s') . "] [INFO] [users] [DB:none] [IP:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "] User role changed to $new_role by admin: " . $_SESSION['username'] . PHP_EOL;
                file_put_contents($log_dir . DIRECTORY_SEPARATOR . 'system.log', $log_entry, FILE_APPEND | LOCK_EX);

            } catch (PDOException $e) {
                showMessage("Error updating user role!", 'error');
            }
        }
        redirect('users.php');
    } elseif ($action === 'delete_user') {
        $user_id = (int) ($_POST['user_id'] ?? 0);

        if ($user_id > 0 && $user_id !== $_SESSION['user_id']) {
            try {
                // First delete user sessions
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                $stmt->execute([$user_id]);

                // Then delete user
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id]);

                showMessage("User deleted successfully!", 'success');

                // Log action
                $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
                if (!is_dir($log_dir))
                    mkdir($log_dir, 0755, true);
                $log_entry = "[" . date('Y-m-d H:i:s') . "] [INFO] [users] [DB:none] [IP:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "] User deleted by admin: " . $_SESSION['username'] . PHP_EOL;
                file_put_contents($log_dir . DIRECTORY_SEPARATOR . 'system.log', $log_entry, FILE_APPEND | LOCK_EX);

            } catch (PDOException $e) {
                showMessage("Error deleting user!", 'error');
            }
        }
        redirect('users.php');
    }
}

/**
 * Get all users / Ottieni l'elenco di tutti gli utenti
 */
$pdo = getAuthDB();
$stmt = $pdo->query("
    SELECT u.*, 
           COUNT(s.id) as active_sessions,
           MAX(u.last_login) as last_login_formatted
    FROM users u 
    LEFT JOIN user_sessions s ON u.id = s.user_id AND s.expires_at > NOW()
    GROUP BY u.id 
    ORDER BY u.created_at DESC
");
$users = $stmt->fetchAll();

/**
 * Get user statistics / Recupera statistiche degli utenti
 */
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_users,
        COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_users,
        COUNT(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_logins
    FROM users
");
$user_stats = $stmt->fetch();
$page_title = 'User Management';
$page_heading = 'User Management';
include 'includes/header.php';
?>


<!-- User Statistics / Statistiche Utente -->
<div class="stats-grid mb-4">
    <div class="stat-card primary">
        <div class="stat-number"><?= $user_stats['total_users'] ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card success">
        <div class="stat-number"><?= $user_stats['active_users'] ?></div>
        <div class="stat-label">Active Users</div>
    </div>
    <div class="stat-card info">
        <div class="stat-number"><?= $user_stats['admin_users'] ?></div>
        <div class="stat-label">Administrators</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-number"><?= $user_stats['recent_logins'] ?></div>
        <div class="stat-label">Recent Activity</div>
    </div>
</div>

<!-- User List / Elenco Utenti -->
<div class="card mb-4">
    <div class="card-header">
        <div>
            <div class="card-title">👥 System Users</div>
            <div class="card-subtitle">Manage accounts, roles, and active sessions</div>
        </div>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Last Login</th>
                    <th>Active Sessions</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <?= $user['id'] ?>
                        </td>
                        <td>
                            <strong>
                                <?= sanitize($user['username']) ?>
                            </strong>
                            <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                <small class="text-muted">(You)</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= sanitize($user['email']) ?>
                        </td>
                        <td>
                            <span class="status-badge role-<?= $user['role'] ?>">
                                <?= strtoupper($user['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= date('Y-m-d H:i', strtotime($user['created_at'])) ?>
                        </td>
                        <td>
                            <?= $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never' ?>
                        </td>
                        <td>
                            <?= $user['active_sessions'] ?>
                            <?php if ($user['active_sessions'] > 0): ?>
                                <span class="text-success">●</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                <!-- Toggle Status -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $user['is_active'] ?>">
                                    <button type="submit"
                                        class="btn btn-sm btn-<?= $user['is_active'] ? 'warning' : 'success' ?>">
                                        <?= $user['is_active'] ? '🚫 Deactivate' : '✅ Activate' ?>
                                    </button>
                                </form>

                                <!-- Change Role -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="change_role">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <select name="role" class="form-select"
                                        style="width: auto; display: inline-block; font-size: 0.8rem;"
                                        onchange="this.form.submit()">
                                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                </form>

                                <!-- Delete User -->
                                <form method="POST" style="display: inline;"
                                    onsubmit="return confirm('Delete user <?= sanitize($user['username']) ?>? This cannot be undone!')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                </form>
                            <?php else: ?>
                                <em class="text-muted">Current User</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- User Management Tips / Suggerimenti Gestione Utenti -->
<div class="card mt-4">
    <div class="card-header">
        <h3 class="card-title">💡 User Management Tips</h3>
    </div>
    <div class="alert alert-info">
        <ul class="list-unstyled mb-0">
            <li><strong>Roles:</strong> Admins have full access, Users have limited access</li>
            <li><strong>Status:</strong> Inactive users cannot log in</li>
            <li><strong>Sessions:</strong> Shows currently active login sessions</li>
            <li><strong>Security:</strong> Regular password changes are recommended</li>
            <li><strong>Logs:</strong> All user management actions are logged</li>
        </ul>
    </div>
</div>

<script>
    // Auto-refresh user list every 30 seconds
    setTimeout(() => {
        location.reload();
    }, 30000);

    // Add confirmation for critical actions
    document.addEventListener('DOMContentLoaded', function () {
        const dangerButtons = document.querySelectorAll('.btn-danger');
        dangerButtons.forEach(button => {
            if (!button.onclick && !button.getAttribute('onsubmit')) {
                button.addEventListener('click', function (e) {
                    if (!confirm('Are you sure? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            }
        });
    });
</script>
<?php include 'includes/footer.php'; ?>