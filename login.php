<?php
require_once 'config.php';

if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

/**
 * Initialize authentication database / Inizializza il database di autenticazione
 */
function initAuthDatabase(): PDO {
    try {
        $pdo = new PDO("mysql:host=" . AUTH_DB_HOST, AUTH_DB_USER, AUTH_DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . AUTH_DB_NAME . "`");
        $pdo->exec("USE `" . AUTH_DB_NAME . "`");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','user') DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                is_active BOOLEAN DEFAULT TRUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                session_token VARCHAR(255) UNIQUE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL DEFAULT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                ip_address VARCHAR(45) PRIMARY KEY,
                attempts INT DEFAULT 1,
                last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Create default admin if none exists / Crea admin predefinito se non esiste
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $hash = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("INSERT INTO users (username,email,password_hash,role) VALUES ('admin','admin@localhost',?,'admin')")
                ->execute([$hash]);
        }

        return $pdo;
    } catch (PDOException $e) {
        die("Authentication database error: " . $e->getMessage());
    }
}

/**
 * Handle form submissions / Gestione invio moduli
 */
$error_message   = '';
$success_message = '';
$active_tab      = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // ── Login / Accesso ──
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username && $password) {
            $pdo = initAuthDatabase();
            
            // Rate Limiting Check
            $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $attempt = $stmt->fetch();
            
            if ($attempt && $attempt['attempts'] >= 5 && strtotime($attempt['last_attempt']) > strtotime('-15 minutes')) {
                $error_message = 'Too many failed login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT id, username, email, password_hash, role, is_active
                        FROM users WHERE (username = ? OR email = ?) AND is_active = 1
                    ");
                    $stmt->execute([$username, $username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Success: clear attempts
                        $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
                        
                        $_SESSION['user_id']  = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email']    = $user['email'];
                        $_SESSION['role']     = $user['role'];
                        $_SESSION['logged_in'] = true;

                        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

                        $token     = bin2hex(random_bytes(32));
                        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                        $pdo->prepare("
                            INSERT INTO user_sessions (user_id,session_token,expires_at,ip_address,user_agent)
                            VALUES (?,?,?,?,?)
                        ")->execute([
                            $user['id'], $token, $expiresAt, $ip,
                            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        ]);
                        $_SESSION['session_token'] = $token;

                        header('Location: index.php');
                        exit;
                    } else {
                        // Track failures
                        $pdo->prepare("INSERT INTO login_attempts (ip_address, attempts) VALUES (?, 1) 
                                       ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP")
                            ->execute([$ip]);
                        $error_message = 'Invalid username/email or password.';
                    }
                } catch (PDOException $e) {
                    $error_message = 'A database error occurred.';
                }
            }
        } else {
            $error_message = 'Please enter both username and password.';
        }
    }

    // ── Register / Registrazione ──
    elseif ($action === 'register') {
        $active_tab = 'register';
        $username   = trim($_POST['reg_username'] ?? '');
        $email      = trim($_POST['reg_email'] ?? '');
        $password   = $_POST['reg_password'] ?? '';
        $confirm    = $_POST['reg_confirm_password'] ?? '';

        if (!$username || !$email || !$password || !$confirm) {
            $error_message = 'Please fill in all registration fields.';
        } elseif ($password !== $confirm) {
            $error_message = 'Passwords do not match.';
        } elseif (strlen($password) < 6) {
            $error_message = 'Password must be at least 6 characters.';
        } else {
            $pdo = initAuthDatabase();
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=? OR email=?");
                $stmt->execute([$username, $email]);
                if ($stmt->fetchColumn() > 0) {
                    $error_message = 'Username or email already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare("INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,'user')")
                        ->execute([$username, $email, $hash]);
                    $success_message = 'Account created! You can now log in.';
                    $active_tab = 'login';
                }
            } catch (PDOException $e) {
                $error_message = 'Registration error.';
            }
        }
    }
}

initAuthDatabase();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="GestioneDb — Professional MySQL Database Manager Login">
    <title>Login — GestioneDb</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🗄️</text></svg>">
</head>
<body class="login-page">

    <div class="login-card">

        <!-- Logo / Logo -->
        <div class="login-logo">
            <div class="login-logo-icon">🗄️</div>
            <h1 class="login-title">GestioneDb</h1>
            <p class="login-subtitle">Professional MySQL Database Manager</p>
        </div>

        <!-- Flash messages / Messaggi di stato -->
        <?php if ($error_message): ?>
        <div class="alert alert-danger" role="alert">
            <span>❌</span>
            <span><?= htmlspecialchars($error_message) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
        <div class="alert alert-success" role="alert">
            <span>✅</span>
            <span><?= htmlspecialchars($success_message) ?></span>
        </div>
        <?php endif; ?>

        <!-- Tabs / Schede -->
        <div class="tabs" role="tablist">
            <button class="tab-btn <?= $active_tab === 'login' ? 'active' : '' ?>"
                    onclick="switchTab('login')" role="tab"
                    aria-selected="<?= $active_tab === 'login' ? 'true' : 'false' ?>"
                    id="tab-login-btn">
                🔑 <?= __('login') ?>
            </button>
            <button class="tab-btn <?= $active_tab === 'register' ? 'active' : '' ?>"
                    onclick="switchTab('register')" role="tab"
                    aria-selected="<?= $active_tab === 'register' ? 'true' : 'false' ?>"
                    id="tab-register-btn">
                👤 <?= __('register') ?>
            </button>
        </div>

        <!-- Login Form / Modulo di Accesso -->
        <div class="tab-panel <?= $active_tab === 'login' ? 'active' : '' ?>" id="panel-login" role="tabpanel">
            <form method="POST" action="login.php" novalidate>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label" for="username">Username or Email</label>
                    <input type="text" name="username" id="username" class="form-input"
                           placeholder="admin" required autofocus autocomplete="username">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password"><?= __('password') ?></label>
                    <input type="password" name="password" id="password" class="form-input"
                           placeholder="••••••••" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="btn-login">
                    🔑 <?= __('login') ?>
                </button>
            </form>

            <div class="login-default-creds">
                <p>
                    <strong>Default credentials</strong><br>
                    Username: <strong>admin</strong> &nbsp;|&nbsp; Password: <strong>admin123</strong><br>
                    <small style="opacity:.7">⚠️ Change these after first login.</small>
                </p>
            </div>
        </div>

        <!-- Register Form / Modulo di Registrazione -->
        <div class="tab-panel <?= $active_tab === 'register' ? 'active' : '' ?>" id="panel-register" role="tabpanel">
            <form method="POST" action="login.php" novalidate>
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label" for="reg_username"><?= __('username') ?></label>
                    <input type="text" name="reg_username" id="reg_username" class="form-input"
                           placeholder="johndoe" required autocomplete="username">
                </div>

                <div class="form-group">
                    <label class="form-label" for="reg_email"><?= __('email') ?></label>
                    <input type="email" name="reg_email" id="reg_email" class="form-input"
                           placeholder="john@example.com" required autocomplete="email">
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:1">
                        <label class="form-label" for="reg_password"><?= __('password') ?></label>
                        <input type="password" name="reg_password" id="reg_password" class="form-input"
                               placeholder="••••••••" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="form-group" style="flex:1">
                        <label class="form-label" for="reg_confirm_password"><?= __('confirm_password') ?></label>
                        <input type="password" name="reg_confirm_password" id="reg_confirm_password" class="form-input"
                               placeholder="••••••••" required autocomplete="new-password">
                    </div>
                </div>

                <button type="submit" class="btn btn-success btn-block" id="btn-register">
                    👤 <?= __('register') ?>
                </button>
            </form>
        </div>

    </div><!-- /.login-card -->

    <script>
        function switchTab(name) {
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            document.getElementById('panel-' + name).classList.add('active');
            const btn = document.getElementById('tab-' + name + '-btn');
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
        }

        // Auto-dismiss alerts / Nascondi automaticamente gli avvisi
        document.querySelectorAll('.alert').forEach(el => {
            setTimeout(() => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            }, 5000);
        });
    </script>
</body>
</html>