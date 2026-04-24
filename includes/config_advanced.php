<?php
/**
 * MySQL Database Manager Pro - Advanced Configuration
 * 
 * Enhanced configuration with security, authentication, logging, and cloud integration
 * 
 * @version 2.0.0
 * @author Database Manager Team
 */

// Prevent direct access
defined('DB_MANAGER_ACCESS') or die('Direct access denied');

// Environment configuration
$env = $_SERVER['DB_MANAGER_ENV'] ?? 'development';
$is_production = $env === 'production';

// Security headers
if ($is_production || ($_SERVER['HTTPS'] ?? false)) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' cdnjs.cloudflare.com; style-src \'self\' \'unsafe-inline\' cdnjs.cloudflare.com; img-src \'self\' data:; font-src \'self\' cdnjs.cloudflare.com;');

// Error reporting
if ($is_production) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', $is_production ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 3600); // 1 hour
session_start();

// Core constants
define('DB_MANAGER_VERSION', '2.0.0');
define('DB_MANAGER_ROOT', dirname(__DIR__));
define('DB_MANAGER_UPLOADS', DB_MANAGER_ROOT . '/uploads');
define('DB_MANAGER_BACKUPS', DB_MANAGER_ROOT . '/backups');
define('DB_MANAGER_LOGS', DB_MANAGER_ROOT . '/logs');
define('DB_MANAGER_CACHE', DB_MANAGER_ROOT . '/cache');

// Database configuration (Enhanced)
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'dbmanager_system'); // Sistema di gestione
define('DB_CHARSET', 'utf8mb4');
define('DB_POOL_SIZE', 10);

// JWT Configuration
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? base64_encode(random_bytes(32)));
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRY', 3600); // 1 hour
define('JWT_REFRESH_EXPIRY', 604800); // 1 week

// Email Configuration (PHPMailer)
define('MAIL_HOST', $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com');
define('MAIL_PORT', $_ENV['MAIL_PORT'] ?? 587);
define('MAIL_USERNAME', $_ENV['MAIL_USERNAME'] ?? '');
define('MAIL_PASSWORD', $_ENV['MAIL_PASSWORD'] ?? '');
define('MAIL_ENCRYPTION', $_ENV['MAIL_ENCRYPTION'] ?? 'tls');
define('MAIL_FROM_ADDRESS', $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@dbmanager.local');
define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME'] ?? 'Database Manager Pro');

// SMS Configuration (Twilio)
define('SMS_SID', $_ENV['TWILIO_SID'] ?? '');
define('SMS_TOKEN', $_ENV['TWILIO_TOKEN'] ?? '');
define('SMS_FROM', $_ENV['TWILIO_FROM'] ?? '');

// Cloud Storage Configuration
define('AWS_ACCESS_KEY', $_ENV['AWS_ACCESS_KEY'] ?? '');
define('AWS_SECRET_KEY', $_ENV['AWS_SECRET_KEY'] ?? '');
define('AWS_REGION', $_ENV['AWS_REGION'] ?? 'us-east-1');
define('AWS_BUCKET', $_ENV['AWS_BUCKET'] ?? '');

define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT_URI', $_ENV['GOOGLE_REDIRECT_URI'] ?? '');

// Security Configuration
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 60); // seconds
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);
define('REQUIRE_2FA', false);

// Cache Configuration
define('CACHE_DRIVER', $_ENV['CACHE_DRIVER'] ?? 'file'); // file, redis, memcached
define('CACHE_TTL', 3600);
define('REDIS_HOST', $_ENV['REDIS_HOST'] ?? 'localhost');
define('REDIS_PORT', $_ENV['REDIS_PORT'] ?? 6379);
define('REDIS_PASSWORD', $_ENV['REDIS_PASSWORD'] ?? '');

// Logging Configuration
define('LOG_LEVEL', $_ENV['LOG_LEVEL'] ?? ($is_production ? 'error' : 'debug'));
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('LOG_MAX_FILES', 10);
define('LOG_CHANNELS', [
    'system' => DB_MANAGER_LOGS . '/system.log',
    'security' => DB_MANAGER_LOGS . '/security.log',
    'query' => DB_MANAGER_LOGS . '/query.log',
    'performance' => DB_MANAGER_LOGS . '/performance.log',
    'api' => DB_MANAGER_LOGS . '/api.log',
    'backup' => DB_MANAGER_LOGS . '/backup.log'
]);

// Backup Configuration
define('BACKUP_RETENTION_DAYS', 30);
define('BACKUP_COMPRESSION', true);
define('BACKUP_CHUNK_SIZE', 1024 * 1024); // 1MB chunks
define('BACKUP_MAX_EXECUTION_TIME', 3600); // 1 hour

// Import/Export Configuration
define('IMPORT_MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('IMPORT_BATCH_SIZE', 1000);
define('EXPORT_CHUNK_SIZE', 10000);

// Monitoring Configuration
define('MONITOR_SLOW_QUERY_TIME', 2.0); // seconds
define('MONITOR_MEMORY_LIMIT', 128 * 1024 * 1024); // 128MB
define('MONITOR_DISK_USAGE_WARNING', 85); // percentage

// Create necessary directories
$directories = [
    DB_MANAGER_UPLOADS,
    DB_MANAGER_BACKUPS,
    DB_MANAGER_LOGS,
    DB_MANAGER_CACHE,
    DB_MANAGER_UPLOADS . '/temp',
    DB_MANAGER_UPLOADS . '/avatars',
    DB_MANAGER_UPLOADS . '/imports'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all");
    }
}

// Autoloader per nuove classi
spl_autoload_register(function ($class) {
    $class = str_replace('\\', '/', $class);
    $paths = [
        DB_MANAGER_ROOT . '/includes/classes/' . $class . '.php',
        DB_MANAGER_ROOT . '/includes/' . $class . '.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

/**
 * Enhanced Database Connection Class with Connection Pooling
 */
class DatabaseManager
{
    private static $instance = null;
    private $connections = [];
    private $current_database = null;
    private $logger;
    private $cache;
    
    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->cache = CacheManager::getInstance();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get database connection with pooling
     */
    public function getConnection(string $database = null): PDO
    {
        $db_name = $database ?: DB_NAME;
        $connection_key = $db_name;
        
        if (!isset($this->connections[$connection_key])) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
                if ($db_name) {
                    $dsn .= ";dbname=" . $db_name;
                }
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => true,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                ];
                
                $this->connections[$connection_key] = new PDO($dsn, DB_USER, DB_PASS, $options);
                $this->logger->info('Database connection established', ['database' => $db_name]);
                
            } catch (PDOException $e) {
                $this->logger->error('Database connection failed', [
                    'database' => $db_name,
                    'error' => $e->getMessage()
                ]);
                throw new Exception("Database connection failed: " . $e->getMessage());
            }
        }
        
        $this->current_database = $db_name;
        return $this->connections[$connection_key];
    }
    
    /**
     * Execute query with caching and logging
     */
    public function query(string $sql, array $params = [], string $database = null, bool $use_cache = false): PDOStatement
    {
        $start_time = microtime(true);
        $connection = $this->getConnection($database);
        
        // Cache key per SELECT queries
        $cache_key = null;
        if ($use_cache && stripos(trim($sql), 'SELECT') === 0) {
            $cache_key = 'query_' . md5($sql . serialize($params) . ($database ?: ''));
            $cached_result = $this->cache->get($cache_key);
            if ($cached_result !== false) {
                $this->logger->debug('Query cache hit', ['sql' => $sql]);
                return $cached_result;
            }
        }
        
        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            
            $execution_time = microtime(true) - $start_time;
            
            // Log slow queries
            if ($execution_time > MONITOR_SLOW_QUERY_TIME) {
                $this->logger->warning('Slow query detected', [
                    'sql' => $sql,
                    'params' => $params,
                    'execution_time' => $execution_time,
                    'database' => $database ?: $this->current_database
                ]);
            }
            
            // Cache SELECT results
            if ($cache_key && $stmt->rowCount() < 10000) { // Don't cache very large results
                $this->cache->set($cache_key, $stmt, CACHE_TTL);
            }
            
            // Log query per audit
            if (getCurrentUser()) {
                $this->logger->info('Query executed', [
                    'sql' => substr($sql, 0, 200),
                    'execution_time' => $execution_time,
                    'rows_affected' => $stmt->rowCount(),
                    'user_id' => getCurrentUser()['id'] ?? null
                ]);
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->logger->error('Query execution failed', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'database' => $database ?: $this->current_database
            ]);
            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(string $database = null): bool
    {
        $connection = $this->getConnection($database);
        return $connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit(string $database = null): bool
    {
        $connection = $this->getConnection($database);
        return $connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback(string $database = null): bool
    {
        $connection = $this->getConnection($database);
        return $connection->rollback();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId(string $database = null): string
    {
        $connection = $this->getConnection($database);
        return $connection->lastInsertId();
    }
    
    /**
     * Close all connections
     */
    public function closeConnections(): void
    {
        $this->connections = [];
        $this->logger->info('All database connections closed');
    }
}

/**
 * Utility functions
 */
function sanitizeInput($data)
{
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateCSRF(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRF(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function getCurrentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function hasPermission(string $resource, string $action): bool
{
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Super admin has all permissions
    if (in_array('super_admin', $user['roles'] ?? [])) {
        return true;
    }
    
    // Check specific permissions
    $permissions = $user['permissions'] ?? [];
    return isset($permissions[$resource]) && 
           (in_array('*', $permissions[$resource]) || in_array($action, $permissions[$resource]));
}

function redirect(string $url, int $status_code = 302): void
{
    header("Location: $url", true, $status_code);
    exit;
}

function jsonResponse(array $data, int $status_code = 200): void
{
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function showMessage(string $message, string $type = 'info'): void
{
    $_SESSION['flash_messages'][] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => time()
    ];
}

function getMessages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

function timeAgo(string $datetime): string
{
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j, Y', strtotime($datetime));
}

function generateStrongPassword(int $length = 16): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle(str_repeat($chars, $length)), 0, $length);
}

function validateStrongPassword(string $password): array
{
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*]/', $password)) {
        $errors[] = "Password must contain at least one special character (!@#$%^&*)";
    }
    
    return $errors;
}

// Initialize core services
try {
    DatabaseManager::getInstance();
    Logger::getInstance();
    CacheManager::getInstance();
    
    // Load system settings
    SettingsManager::loadSettings();
    
} catch (Exception $e) {
    if (!$is_production) {
        die("Initialization error: " . $e->getMessage());
    } else {
        error_log("Critical initialization error: " . $e->getMessage());
        die("System temporarily unavailable. Please try again later.");
    }
}
?>
