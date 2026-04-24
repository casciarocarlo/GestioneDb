<?php
/**
 * Authentication middleware / Middleware di autenticazione
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Database configuration for authentication / Configurazione database per autenticazione
 * (Loaded from environment / Caricata dall'ambiente)
 */
if (!defined('AUTH_DB_HOST')) define('AUTH_DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('AUTH_DB_USER')) define('AUTH_DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('AUTH_DB_PASS')) define('AUTH_DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('AUTH_DB_NAME')) define('AUTH_DB_NAME', 'db_manager_auth');

/**
 * Check if user is authenticated / Verifica se l'utente è autenticato
 */
function isAuthenticated() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_id']);
}

/**
 * Check if user has specific role / Verifica se l'utente ha un ruolo specifico
 */
function hasRole($required_role) {
    if (!isAuthenticated()) {
        return false;
    }
    
    $user_role = $_SESSION['role'] ?? 'user';
    
    if ($required_role === 'admin') {
        return $user_role === 'admin';
    }
    
    return true; // All authenticated users have 'user' role access / Tutti gli utenti hanno accesso base
}

/**
 * Get current user info / Ottieni informazioni sull'utente corrente
 */
function getCurrentUser() {
    if (!isAuthenticated()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'email' => $_SESSION['email'],
        'role' => $_SESSION['role']
    ];
}

/**
 * Requires authentication or redirect / Richiede autenticazione o reindirizza
 */
function requireAuth($redirect = true) {
    if (!isAuthenticated()) {
        if ($redirect) {
            header('Location: login.php');
            exit;
        }
        return false;
    }
    return true;
}

/**
 * Requires admin role or redirect / Richiede ruolo admin o reindirizza
 */
function requireAdmin($redirect = true) {
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
 * Validates session token against database / Valida il token di sessione nel database
 */
function validateSessionToken() {
    if (!isAuthenticated() || !isset($_SESSION['session_token'])) {
        return false;
    }
    
    try {
        $pdo = new PDO("mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME, AUTH_DB_USER, AUTH_DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("
            SELECT expires_at 
            FROM user_sessions 
            WHERE user_id = ? AND session_token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
        $session = $stmt->fetch();
        
        if (!$session) {
            // Session expired or invalid
            session_destroy();
            return false;
        }
        
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Logs out user and destroys session / Esegue il logout e distrugge la sessione
 */
function logoutUser() {
    if (isset($_SESSION['session_token']) && isset($_SESSION['user_id'])) {
        try {
            $pdo = new PDO("mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME, AUTH_DB_USER, AUTH_DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
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

// Session validation moved to individual pages - no automatic validation here
?>
