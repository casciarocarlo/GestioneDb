<?php
require_once __DIR__ . '/config.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Initialize authentication database / Inizializza il database di autenticazione
 */
if (!function_exists('initAuthDatabase')) {
function initAuthDatabase(): PDO {
    try {
        $dbHost = defined('AUTH_DB_HOST') ? AUTH_DB_HOST : (defined('DB_HOST') ? DB_HOST : env('DB_HOST', 'localhost'));
        $dbUser = defined('AUTH_DB_USER') ? AUTH_DB_USER : (defined('DB_USER') ? DB_USER : env('DB_USER', 'root'));
        $dbPass = defined('AUTH_DB_PASS') ? AUTH_DB_PASS : (defined('DB_PASS') ? DB_PASS : env('DB_PASS', ''));
        $dbName = defined('AUTH_DB_NAME') ? AUTH_DB_NAME : (defined('DB_NAME') && DB_NAME !== '' ? DB_NAME : env('DB_NAME', 'db_gestionedb'));

        $pdo = new PDO("mysql:host=" . $dbHost, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $dbName . "`");
        $pdo->exec("USE `" . $dbName . "`");

        // Create users table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','user') DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                is_active BOOLEAN DEFAULT TRUE,
                theme_preference ENUM('light','dark','system') DEFAULT 'system',
                password_changed_at TIMESTAMP NULL,
                failed_login_attempts INT DEFAULT 0,
                account_locked_until TIMESTAMP NULL,
                CONSTRAINT chk_username_length CHECK (CHAR_LENGTH(username) >= 3),
                CONSTRAINT chk_email_length CHECK (CHAR_LENGTH(email) >= 5),
                CONSTRAINT chk_password_complexity CHECK (
                    password_hash IS NOT NULL
                    AND (LENGTH(password_hash) >= 60) -- BCRYPT hash length check
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Create sessions table
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
        
        // Create login attempts tracking
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                ip_address VARCHAR(45) PRIMARY KEY,
                attempts INT DEFAULT 1,
                last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                account_locked_until TIMESTAMP NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Add password_changed_at column if missing
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL");
        } catch (Exception $e) {
            // Column already exists, ignore
        }
        
        // Create default admin if none exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $defaultUser = defined('DEFAULT_ADMIN_USER') ? DEFAULT_ADMIN_USER : 'admin';
            $defaultPass = defined('DEFAULT_ADMIN_PASS') ? DEFAULT_ADMIN_PASS : 'admin123';
            $hash = password_hash($defaultPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("INSERT INTO users (username,email,password_hash,role) VALUES (?,?,?,'admin')")
                ->execute([$defaultUser, $defaultUser . '@localhost', $hash]);
        }

        return $pdo;
    } catch (PDOException $e) {
        die("Authentication database error: " . $e->getMessage());
    }
}
}

/**
 * Enhanced password complexity checker / Controllo di complessità password potenziato
 */
function validatePasswordComplexity(string $password): array {
    $errors = [];
    
    // Minimum length check
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    
    // Uppercase check
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    
    // Lowercase check
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    
    // Number check
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    
    // Special character check
    if (!preg_match('/[\W_]/', $password)) {
        $errors[] = 'Password must contain at least one special character';
    }
    
    return $errors;
}

/**
 * Enhanced login handler with rate limiting / Gestione login migliorata con limitazione
 */
function handleLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    
    $action = $_POST['action'] ?? '';
    if ($action !== 'login') {
        return;
    }
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Validate input
    if (!$username || !$password) {
        $_SESSION['error_message'] = 'Please enter both username and password';
        header('Location: login.php');
        exit;
    }
    
    // Initialize auth database
    $pdo = initAuthDatabase();
    
    // Rate limiting check
    $stmt = $pdo->prepare("SELECT attempts, last_attempt, account_locked_until FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $attempt = $stmt->fetch();
    
    $now = new DateTimeImmutable();
    
    if ($attempt) {
        $lastAttempt = new DateTimeImmutable($attempt['last_attempt']);
        $lockedUntil = $attempt['account_locked_until'] ? new DateTimeImmutable($attempt['account_locked_until']) : null;
        
        // Check if account is locked
        if ($lockedUntil && $lockedUntil > $now) {
            $remaining = $lockedUntil->diff($now)->s;
            $_SESSION['error_message'] = "Too many failed attempts. Try again in {$remaining} seconds.";
            header('Location: login.php');
            exit;
        }
        
        // Check if we're exceeding attempt limit
        if ($attempt['attempts'] >= 5) {
            // Lock account for 15 minutes
            $lockUntil = $now->modify('+15 minutes');
            $pdo->prepare("UPDATE login_attempts SET account_locked_until = ? WHERE ip_address = ?")
                ->execute([$lockUntil->format('Y-m-d H:i:s'), $ip]);
            
            $_SESSION['error_message'] = 'Too many failed login attempts. Account locked for 15 minutes.';
            header('Location: login.php');
            exit;
        }
    }
    
    // Check user existence
    $stmt = $pdo->prepare("
        SELECT id, username, email, password_hash, role, is_active, 
               failed_login_attempts, account_locked_until, password_changed_at
        FROM users 
        WHERE (username = ? OR email = ?) AND is_active = 1
    ");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Increment failed attempts for unknown user
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, attempts) 
            VALUES (?, 1) 
            ON DUPLICATE KEY UPDATE attempts = attempts + 1
        ");
        $stmt->execute([$ip]);
        
        $_SESSION['error_message'] = 'Invalid username or password';
        header('Location: login.php');
        exit;
    }
    
    // Check if account is locked
    if ($user['account_locked_until']) {
        $lockedUntil = new DateTimeImmutable($user['account_locked_until']);
        if ($lockedUntil > $now) {
            $remaining = $lockedUntil->diff($now)->s;
            $_SESSION['error_message'] = "Account locked. Try again in {$remaining} seconds.";
            header('Location: login.php');
            exit;
        } else {
            // Reset failed attempts if lock period expired
            $pdo->prepare("UPDATE login_attempts SET attempts = 1 WHERE ip_address = ?")
                ->execute([$ip]);
        }
    }
    
    // Verify password
    $passwordVerify = password_verify($password, $user['password_hash']);
    
    if ($passwordVerify) {
        // Reset failed attempts
        $pdo->prepare("UPDATE login_attempts SET attempts = 1 WHERE ip_address = ?")
            ->execute([$ip]);
        
        // Update last login time
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);
        
        // Regenerate session token
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())->modify('+7 days')->format('Y-m-d H:i:s');
        
        // Remove old session tokens
        $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")
            ->execute([$user['id']]);
        
        // Store new session token
        $pdo->prepare("
            INSERT INTO user_sessions (user_id, session_token, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $user['id'], $token, $expiresAt, $ip,
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Update session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['theme_preference'] = $user['theme_preference'] ?? 'dark';
        $_SESSION['logged_in'] = true;
        $_SESSION['session_token'] = $token;
        $_SESSION['password_changed_at'] = $user['password_changed_at'] ?? null;
        
        // Mark password as changed (first login)
        if (!$user['password_changed_at']) {
            $pdo->prepare("UPDATE users SET password_changed_at = NOW() WHERE id = ?")
                ->execute([$user['id']]);
        }
        
        // Clear error messages
        unset($_SESSION['error_message']);
        
        header('Location: index.php');
        exit;
    } else {
        // Increment failed attempts
        $failedAttempts = ($user['failed_login_attempts'] ?? 0) + 1;
        $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")
            ->execute([$failedAttempts, $user['id']]);
        
        // Update login attempts tracking
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, attempts) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE attempts = attempts + 1
        ");
        $stmt->execute([$ip]);
        
        // Update failed attempts counter in users table
        $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")
            ->execute([$failedAttempts, $user['id']]);
        
        // Check if account should be locked
        if ($failedAttempts >= 3) {
            $lockUntil = (new DateTimeImmutable())->modify('+15 minutes');
            $pdo->prepare("UPDATE users SET account_locked_until = ? WHERE id = ?")
                ->execute([$lockUntil->format('Y-m-d H:i:s'), $user['id']]);
            
            $pdo->prepare("UPDATE login_attempts SET account_locked_until = ? WHERE ip_address = ?")
                ->execute([$lockUntil->format('Y-m-d H:i:s'), $ip]);
        }
        
        // Update last attempt time
        $pdo->prepare("UPDATE login_attempts SET last_attempt = CURRENT_TIMESTAMP WHERE ip_address = ?")
            ->execute([$ip]);
        
        $_SESSION['error_message'] = 'Invalid username or password';
        header('Location: login.php');
        exit;
    }
}

/**
 * Enhanced logout function / Funzione di logout migliorata
 */
function enhancedLogout(): void {
    if (isset($_SESSION['session_token']) && isset($_SESSION['user_id'])) {
        try {
            $pdo = initAuthDatabase();
            
            // Remove session token from database
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_token = ?");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
            
            // Log logout
            $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
            
            $username = $_SESSION['username'] ?? 'unknown';
            $log_entry = "[" . date('Y-m-d H:i:s') . "] [INFO] [auth] [DB:none] [IP:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "] User logged out: " . $username . PHP_EOL;
            file_put_contents($log_dir . DIRECTORY_SEPARATOR . 'system.log', $log_entry, FILE_APPEND | LOCK_EX);
            
        } catch (PDOException $e) {
            // Continue with session destruction even if database operation fails
        }
    }
    
    // Destroy session
    session_destroy();
}

/**
 * Logout user alias / Alias per disconnettere l'utente
 */
function logoutUser(): void {
    enhancedLogout();
}

/**
 * Enhanced session validation with token refresh / Validazione sessione migliorata con refresh token
 */
function enhancedValidateSessionToken(): bool {
    if (!isset($_SESSION['session_token']) || !isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        $pdo = initAuthDatabase();
        
        $stmt = $pdo->prepare("
            SELECT expires_at 
            FROM user_sessions 
            WHERE user_id = ? AND session_token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
        $session = $stmt->fetch();
        
        if (!$session) {
            // Session expired or invalid - force logout
            enhancedLogout();
            return false;
        }
        
        // Refresh token if it's older than 24 hours
        $expiresAt = new DateTimeImmutable($session['expires_at']);
        $now = new DateTimeImmutable();
        
        if ($now > $expiresAt->modify('-24 hours')) {
            // Refresh the token
            $newToken = bin2hex(random_bytes(32));
            $newExpiresAt = (new DateTimeImmutable())->modify('+7 days')->format('Y-m-d H:i:s');
            
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")
                ->execute([$_SESSION['user_id']]);
                
            $pdo->prepare("
                INSERT INTO user_sessions (user_id, session_token, expires_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $_SESSION['user_id'], $newToken, $newExpiresAt, $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
            $_SESSION['session_token'] = $newToken;
        }
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Validate session token / Valida token sessione
 */
function validateSessionToken(): bool {
    return enhancedValidateSessionToken();
}

/**
 * Enhanced password change with complexity check / Cambio password migliorato con controllo complessità
 */
function changePassword(string $currentPassword, string $newPassword, string $confirmPassword): bool {
    if (!isAuthenticated()) {
        return false;
    }
    
    if ($currentPassword === '') {
        return false;
    }
    
    if ($newPassword !== $confirmPassword) {
        return false;
    }
    
    // Validate new password complexity
    $errors = validatePasswordComplexity($newPassword);
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode(' ', $errors);
        return false;
    }
    
    try {
        $pdo = initAuthDatabase();
        
        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = ?")
            ->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return false;
        }
        
        // Verify current password
        if (!password_verify($currentPassword, $result['password_hash'])) {
            return false;
        }
        
        // Update password
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?")
            ->execute([$newHash, $_SESSION['user_id']]);
        
        // Reset failed login attempts
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0 WHERE id = ?")
            ->execute([$_SESSION['user_id']]);
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Enhanced CSRF protection / Protezione CSRF migliorata
 */
function enhancedGenerateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(64)); // Longer token
    }
    return $_SESSION['csrf_token'];
}

function enhancedValidateCSRF(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function enhancedRequireAuth($redirect = true) {
    if (!isAuthenticated()) {
        if ($redirect) {
            header('Location: login.php');
            exit;
        }
        return false;
    }
    return true;
}

function enhancedRequireAdmin($redirect = true) {
    if (!hasRole('admin')) {
        if ($redirect) {
            if (!isAuthenticated()) {
                header('Location: login.php');
            } else {
                // User is authenticated but not admin
                $_SESSION['error_message'] = 'Access denied. Administrator privileges required.';
                header('Location: index.php');
            }
            exit;
        }
        return false;
    }
    return true;
}

/**
 * Compatibility alias for requireAdmin
 */
function requireAdmin(bool $redirect = true): bool {
    return enhancedRequireAdmin($redirect);
}

/**
 * Enhanced password policy enforcement / Applicazione politica password migliorata
 */
function enforcePasswordPolicy(string $password): array {
    $errors = [];
    
    // Check minimum length (8 characters)
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    
    // Check for common patterns
    $commonPatterns = ['password', '123456', '12345678', 'qwerty', 'admin'];
    foreach ($commonPatterns as $pattern) {
        if (stripos($password, $pattern) !== false) {
            $errors[] = 'Password cannot contain common patterns';
        }
    }
    
    // Check for sequential characters
    if (preg_match('/1234|4567|7890|abc|xyz|qwe|rst|uvw/', $password)) {
        $errors[] = 'Password cannot contain sequential characters';
    }
    
    // Check for repeated characters
    if (preg_match('/(.)\1{2,}/', $password)) {
        $errors[] = 'Password cannot contain repeated characters';
    }
    
    return $errors;
}

/**
 * Check if user is authenticated / Verifica se l'utente è autenticato
 */
function isAuthenticated(): bool {
    return isset($_SESSION['session_token']) && enhancedValidateSessionToken();
}

/**
 * Get currently authenticated user data / Ottiene i dati dell'utente autenticato
 */
function getCurrentUser(): ?array {
    if (!isAuthenticated()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? 'user',
        'theme_preference' => $_SESSION['theme_preference'] ?? 'dark',
        'roles' => isset($_SESSION['role']) ? [$_SESSION['role']] : ['user']
    ];
}

/**
 * Check if current user has a specific role / Verifica se l'utente ha un ruolo specifico
 */
function hasRole(string $role): bool {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    return ($user['role'] ?? '') === $role || in_array($role, $user['roles'] ?? []);
}