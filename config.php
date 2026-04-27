<?php
/**
 * GestioneDb - Configuration / Configurazione
 */

// 1. Environment variables support (LOAD FIRST) / Supporto variabili d'ambiente (CARICARE PER PRIMO)
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}

/**
 * Get environment variable / Recupera variabile d'ambiente
 */
function env($key, $default = null)
{
    $val = getenv($key);
    if ($val === false && isset($_ENV[$key])) $val = $_ENV[$key];
    return $val !== false ? $val : $default;
}

// 2. Base Constants / Costanti di base
define('APP_VERSION', '2.0.1');

// 3. Database configuration / Configurazione Database
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME', ''));

// 4. Include authentication system / Includi sistema di autenticazione
require_once __DIR__ . '/auth.php';

// 5. AI Configuration (OpenRouter) / Configurazione AI
define('OPENROUTER_API_KEY', env('AI_API_KEY', ''));
define('OPENROUTER_MODEL', env('AI_MODEL', 'google/gemini-2.0-flash-001'));

// Error reporting / Gestione Errori
$app_env = env('APP_ENV', 'development');
if ($app_env === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Security Headers / Header di Sicurezza
header("X-XSS-Protection: 1; mode=block");
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:;");

/**
 * Database connection class / Classe per la connessione al Database
 */
class Database
{
    private $connection;
    private $current_db;

    public function __construct($database = null)
    {
        try {
            $dsn = "mysql:host=" . DB_HOST;
            if ($database) {
                $dsn .= ";dbname=" . $database;
                $this->current_db = $database;
            }

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getCurrentDatabase()
    {
        return $this->current_db;
    }

    public function getDatabases()
    {
        $stmt = $this->connection->query("SHOW DATABASES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function createDatabase($name)
    {
        $stmt = $this->connection->prepare("CREATE DATABASE `" . str_replace('`', '``', $name) . "`");
        return $stmt->execute();
    }

    public function dropDatabase($name)
    {
        $stmt = $this->connection->prepare("DROP DATABASE `" . str_replace('`', '``', $name) . "`");
        return $stmt->execute();
    }

    public function getTables()
    {
        if (!$this->current_db)
            return [];
        $stmt = $this->connection->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getTableStructure($table)
    {
        $stmt = $this->connection->prepare("DESCRIBE `" . str_replace('`', '``', $table) . "`");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function query($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }
}

// Utility functions
function sanitize($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header("Location: " . $url);
    exit();
}

function showMessage($message, $type = 'info')
{
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

/**
 * Multi-language Support / Supporto Multi-lingua
 */
$available_langs = ['en', 'it'];
$default_lang = 'en';

// Handle language change / Gestione cambio lingua
if (isset($_GET['lang']) && in_array($_GET['lang'], $available_langs)) {
    $_SESSION['lang'] = $_GET['lang'];
    // Redirect back to remove the lang param from URL
    $url = strtok($_SERVER["REQUEST_URI"], '?');
    if (!empty($_SERVER['QUERY_STRING'])) {
        parse_str($_SERVER['QUERY_STRING'], $params);
        unset($params['lang']);
        if (!empty($params)) $url .= '?' . http_build_query($params);
    }
    header("Location: " . $url);
    exit();
}

// Determine current language / Determina la lingua corrente
$lang = $_SESSION['lang'] ?? $default_lang;
$translations = [];

$lang_file = __DIR__ . "/languages/{$lang}.php";
if (file_exists($lang_file)) {
    $translations = include $lang_file;
}

/**
 * Translation helper / Funzione per la traduzione
 */
function __($key, $default = null)
{
    global $translations;
    return $translations[$key] ?? ($default ?: $key);
}

/**
 * Generate CSRF token / Genera token CSRF
 */
function generateCSRF()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token / Valida token CSRF
 */
function validateCSRF($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Security middleware for POST requests / Middleware di sicurezza per richieste POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // List of pages to skip CSRF check if necessary (e.g., login or public webhooks)
    $skip_csrf = []; // Handle all pages
    
    $current_file = basename($_SERVER['PHP_SELF']);
    if (!in_array($current_file, $skip_csrf)) {
        $token = $_POST['csrf_token'] ?? '';
        if (!validateCSRF($token)) {
            header('HTTP/1.1 403 Forbidden');
            die("CSRF token validation failed. Request denied.");
        }
    }
}
?>