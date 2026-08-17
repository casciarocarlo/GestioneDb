<?php
/**
 * GestioneDb - Configuration / Configurazione
 */

// 1. Environment variables support (LOAD FIRST) / Supporto variabili d'ambiente (CARICARE PER PRIMO)
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (strpos($trimmed, '#') === 0 || strpos($trimmed, '=') === false) continue;
        list($name, $value) = explode('=', $trimmed, 2);
        $name  = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

/**
 * Get environment variable / Recupera variabile d'ambiente
 */
function env($key, $default = null)
{
    $val = getenv($key);
    if ($val === false && isset($_ENV[$key])) $val = $_ENV[$key];
    return ($val !== false && $val !== null && $val !== '') ? $val : $default;
}

// 2. Base Constants / Costanti di base
define('APP_VERSION', '2.0.1');

// 3. Security Configuration / Configurazione di Sicurezza
$envSecret = env('APP_SECRET');
if ($envSecret) {
    define('APP_SECRET', $envSecret);
} else {
    define('APP_SECRET', 'GestioneDb_Secret_Key_Change_In_Env');
}

// 4. Database configuration / Configurazione Database
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME', ''));

define('AUTH_DB_HOST', env('AUTH_DB_HOST', DB_HOST));
define('AUTH_DB_USER', env('AUTH_DB_USER', DB_USER));
define('AUTH_DB_PASS', env('AUTH_DB_PASS', DB_PASS));
define('AUTH_DB_NAME', env('AUTH_DB_NAME', DB_NAME !== '' ? DB_NAME : 'db_gestionedb'));

define('DEFAULT_ADMIN_USER', env('DEFAULT_ADMIN_USER', 'admin'));
define('DEFAULT_ADMIN_PASS', env('DEFAULT_ADMIN_PASS', 'admin123'));

// 4. Directory paths / Percorsi directory
define('BACKUP_DIR', __DIR__ . '/backups');
define('LOGS_DIR', __DIR__ . '/logs');
define('EXPORTS_DIR', __DIR__ . '/exports');

// Ensure required directories exist
foreach ([BACKUP_DIR, LOGS_DIR, EXPORTS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// 4. Include authentication system / Includi sistema di autenticazione
require_once __DIR__ . '/auth.php';

/**
 * Encryption helpers for sensitive data (e.g., database passwords)
 * Uses AES-256-CBC with a key derived from APP_SECRET
 */
function encryptValue(string $value): string {
    if ($value === '') return '';
    $key = defined('APP_SECRET') ? APP_SECRET : 'GestioneDb_2025';
    $hashKey = hash('sha256', $key, true);
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($value, 'aes-256-cbc', $hashKey, OPENSSL_RAW_DATA, $iv);
    return 'v2:' . base64_encode($iv . $ciphertext);
}

function decryptValue(string $enc): string {
    if ($enc === '') return '';
    $key = defined('APP_SECRET') ? APP_SECRET : 'GestioneDb_2025';

    // Check if new v2 format
    if (str_starts_with($enc, 'v2:')) {
        $hashKey = hash('sha256', $key, true);
        $data = base64_decode(substr($enc, 3));
        if ($data === false || strlen($data) < 16) return '';
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        if ($ciphertext === '') return '';
        $dec = @openssl_decrypt($ciphertext, 'aes-256-cbc', $hashKey, OPENSSL_RAW_DATA, $iv);
        if ($dec !== false && mb_check_encoding($dec, 'UTF-8')) return $dec;
        return '';
    }

    // Fallback to old XOR method for backward compatibility
    $dec = base64_decode($enc, true);
    if ($dec === false) return '';
    $out = '';
    for ($i = 0; $i < strlen($dec); $i++) {
        $out .= chr(ord($dec[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return mb_check_encoding($out, 'UTF-8') ? $out : '';
}

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
 * Multi-driver Database connection class
 * Supports: mysql, pgsql, sqlsrv, sqlite
 */
class Database
{
    private PDO    $connection;
    private string $current_db;
    private string $driver;

    /**
     * Build from the active session connection (user-defined) or fall back to .env defaults.
     */
    public function __construct(?string $database = null)
    {
        // Prefer the connection stored in session by connections.php
        if (!empty($_SESSION['active_connection_id'])) {
            $driver   = $_SESSION['connection_driver'] ?? 'mysql';
            $host     = $_SESSION['connection_host']   ?? 'localhost';
            $port     = (int)($_SESSION['connection_port'] ?? 3306);
            $db_user  = $_SESSION['connection_user']   ?? 'root';
            $db_pass  = $_SESSION['connection_pass']   ?? '';
            $db_name  = $database ?: ($_SESSION['selected_db'] ?? '');
        } else {
            // Legacy / default — use .env MySQL settings
            $driver   = 'mysql';
            $host     = DB_HOST;
            $port     = 3306;
            $db_user  = DB_USER;
            $db_pass  = DB_PASS;
            $db_name  = $database ?: '';
        }

        $this->driver     = $driver;
        $this->current_db = $db_name;

        try {
            // If the user hasn't configured any connection, block access
        if (empty($_SESSION['active_connection_id'])) {
            // Show friendly message and send to connections management
            if (function_exists('showMessage') && function_exists('redirect')) {
                showMessage('Nessuna connessione configurata. Vai alla gestione connessioni per aggiungerne una.', 'error');
                redirect('connections.php');
            }
            exit;
        }
        $this->connection = self::buildPdo($driver, $host, $port, $db_name, $db_user, $db_pass);

        } catch (PDOException $e) {
            die("Database connection failed ({$driver}): " . $e->getMessage());
        }
    }

    /** Build a PDO connection for any supported driver */
    public static function buildPdo(
        string $driver, string $host, int $port,
        string $db_name, string $db_user, string $db_pass
    ): PDO {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        switch ($driver) {
            case 'mysql':
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                if ($db_name) $dsn .= ";dbname={$db_name}";
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                break;

            case 'pgsql':
                $dsn = "pgsql:host={$host};port={$port}";
                if ($db_name) $dsn .= ";dbname={$db_name}";
                break;

            case 'sqlsrv':
                $dsn = "sqlsrv:Server={$host},{$port}";
                if ($db_name) $dsn .= ";Database={$db_name}";
                break;

            case 'sqlite':
                // db_name is the file path for SQLite
                $dsn = "sqlite:{$db_name}";
                $db_user = '';
                $db_pass = '';
                break;

            default:
                throw new \InvalidArgumentException("Unsupported driver: {$driver}");
        }

        return new PDO($dsn, $db_user ?: null, $db_pass ?: null, $options);
    }

    public function getConnection(): PDO   { return $this->connection; }
    public function getCurrentDatabase(): string { return $this->current_db; }
    public function getDriver(): string    { return $this->driver; }

    /** List user-visible databases (dialect-aware) */
    public function getDatabases(): array
    {
        switch ($this->driver) {
            case 'mysql':
                return $this->connection->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);

            case 'pgsql':
                return $this->connection
                    ->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname")
                    ->fetchAll(PDO::FETCH_COLUMN);

            case 'sqlsrv':
                return $this->connection
                    ->query("SELECT name FROM sys.databases WHERE name NOT IN ('master','tempdb','model','msdb') ORDER BY name")
                    ->fetchAll(PDO::FETCH_COLUMN);

            case 'sqlite':
                // SQLite is a single-file DB; return just the filename as the "database"
                return [$this->current_db ?: 'main'];

            default:
                return [];
        }
    }

    public function createDatabase(string $name): bool
    {
        switch ($this->driver) {
            case 'mysql':
                return $this->connection->prepare("CREATE DATABASE `" . str_replace('`','``',$name) . "`")->execute();
            case 'pgsql':
                return (bool)$this->connection->exec("CREATE DATABASE \"" . addslashes($name) . "\"");
            case 'sqlsrv':
                return (bool)$this->connection->exec("CREATE DATABASE [{$name}]");
            default:
                throw new \RuntimeException("createDatabase not supported for {$this->driver}");
        }
    }

    public function dropDatabase(string $name): bool
    {
        switch ($this->driver) {
            case 'mysql':
                return $this->connection->prepare("DROP DATABASE `" . str_replace('`','``',$name) . "`")->execute();
            case 'pgsql':
                return (bool)$this->connection->exec("DROP DATABASE \"" . addslashes($name) . "\"");
            case 'sqlsrv':
                return (bool)$this->connection->exec("DROP DATABASE [{$name}]");
            default:
                throw new \RuntimeException("dropDatabase not supported for {$this->driver}");
        }
    }

    /** List tables in current DB (dialect-aware) */
    public function getTables(): array
    {
        if (!$this->current_db) return [];

        switch ($this->driver) {
            case 'mysql':
                return $this->connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            case 'pgsql':
                return $this->connection
                    ->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")
                    ->fetchAll(PDO::FETCH_COLUMN);

            case 'sqlsrv':
                return $this->connection
                    ->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
                    ->fetchAll(PDO::FETCH_COLUMN);

            case 'sqlite':
                return $this->connection
                    ->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
                    ->fetchAll(PDO::FETCH_COLUMN);

            default:
                return [];
        }
    }

    /** Describe table columns (normalised to MySQL DESCRIBE format) */
    public function getTableStructure(string $table): array
    {
        switch ($this->driver) {
            case 'mysql':
                $stmt = $this->connection->prepare("DESCRIBE `" . str_replace('`','``',$table) . "`");
                $stmt->execute();
                return $stmt->fetchAll();

            case 'pgsql':
                $stmt = $this->connection->prepare("
                    SELECT column_name AS \"Field\", data_type AS \"Type\",
                           is_nullable AS \"Null\",
                           CASE WHEN pk.column_name IS NOT NULL THEN 'PRI' ELSE '' END AS \"Key\",
                           column_default AS \"Default\", '' AS \"Extra\"
                    FROM information_schema.columns c
                    LEFT JOIN (
                        SELECT ku.column_name FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage ku
                          ON tc.constraint_name = ku.constraint_name
                        WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = ?
                    ) pk ON c.column_name = pk.column_name
                    WHERE c.table_name = ? ORDER BY ordinal_position
                ");
                $stmt->execute([$table, $table]);
                return $stmt->fetchAll();

            case 'sqlsrv':
                $stmt = $this->connection->prepare("
                    SELECT c.COLUMN_NAME AS [Field], c.DATA_TYPE AS [Type],
                           c.IS_NULLABLE AS [Null],
                           CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 'PRI' ELSE '' END AS [Key],
                           c.COLUMN_DEFAULT AS [Default],
                           CASE WHEN COLUMNPROPERTY(OBJECT_ID(c.TABLE_NAME),c.COLUMN_NAME,'IsIdentity')=1 THEN 'auto_increment' ELSE '' END AS [Extra]
                    FROM INFORMATION_SCHEMA.COLUMNS c
                    LEFT JOIN (
                        SELECT ku.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                        JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                        WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = ?
                    ) pk ON c.COLUMN_NAME = pk.COLUMN_NAME
                    WHERE c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION
                ");
                $stmt->execute([$table, $table]);
                return $stmt->fetchAll();

            case 'sqlite':
                $stmt = $this->connection->prepare("PRAGMA table_info(" . $table . ")");
                $stmt->execute();
                $rows = $stmt->fetchAll();
                // Normalise to MySQL DESCRIBE format
                return array_map(fn($r) => [
                    'Field'   => $r['name'],
                    'Type'    => $r['type'],
                    'Null'    => $r['notnull'] ? 'NO' : 'YES',
                    'Key'     => $r['pk'] ? 'PRI' : '',
                    'Default' => $r['dflt_value'],
                    'Extra'   => '',
                ], $rows);

            default:
                return [];
        }
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    public function lastInsertId(): string
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
$available_langs = ['en', 'it', 'fr', 'es'];
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
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
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