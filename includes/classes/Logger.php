<?php
/**
 * Centralized Logging System
 * 
 * PSR-3 compliant logger with multiple channels, log rotation, and email alerts
 * 
 * @version 2.0.0
 */

class Logger
{
    private static $instance = null;
    private $channels = [];
    private $log_level_hierarchy = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7
    ];
    
    // Default configuration values
    private $defaults = [
        'log_level' => 'info',
        'log_max_size' => 10485760, // 10MB
        'log_max_files' => 5,
        'log_retention_days' => 90
    ];
    
    private function __construct()
    {
        $this->initializeChannels();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initializeChannels(): void
    {
        // Default channels with fallback
        $default_channels = [
            'system' => __DIR__ . '/../../logs/system.log',
            'error' => __DIR__ . '/../../logs/error.log',
            'security' => __DIR__ . '/../../logs/security.log',
            'query' => __DIR__ . '/../../logs/query.log',
            'performance' => __DIR__ . '/../../logs/performance.log',
            'api' => __DIR__ . '/../../logs/api.log',
            'backup' => __DIR__ . '/../../logs/backup.log'
        ];
        
        // Use defined channels if available, otherwise use defaults
        if (defined('LOG_CHANNELS') && is_array(LOG_CHANNELS)) {
            $this->channels = array_merge($default_channels, LOG_CHANNELS);
        } else {
            $this->channels = $default_channels;
        }
    }
    
    /**
     * Write log entry
     */
    private function writeLog(string $level, string $message, array $context = [], string $channel = 'system'): void
    {
        // Check if log level should be written
        $current_level = $this->getConfig('log_level', 'info');
        if (!isset($this->log_level_hierarchy[$level]) || !isset($this->log_level_hierarchy[$current_level])) {
            return;
        }
        
        if ($this->log_level_hierarchy[$level] < $this->log_level_hierarchy[$current_level]) {
            return;
        }
        
        $log_file = $this->channels[$channel] ?? $this->channels['system'];
        
        // Prepare log entry
        $timestamp = date('Y-m-d H:i:s');
        $user_info = $this->getUserInfo();
        $request_info = $this->getRequestInfo();
        
        $log_entry = [
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'channel' => $channel,
            'message' => $message,
            'context' => $context,
            'user' => $user_info,
            'request' => $request_info,
            'memory' => memory_get_peak_usage(true),
            'trace' => $this->getStackTrace()
        ];
        
        $formatted_entry = $this->formatLogEntry($log_entry);
        
        // Write to file
        $this->writeToFile($log_file, $formatted_entry);
        
        // Handle critical errors
        if (in_array($level, ['critical', 'alert', 'emergency'])) {
            $this->sendCriticalAlert($log_entry);
        }
        
        // Store in database for web interface
        $this->storeInDatabase($log_entry);
    }
    
    private function formatLogEntry(array $entry): string
    {
        return sprintf(
            "[%s] %s.%s: %s %s\n",
            $entry['timestamp'],
            $entry['level'],
            $entry['channel'],
            $entry['message'],
            !empty($entry['context']) ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE) : ''
        );
    }
    
    private function writeToFile(string $file_path, string $content): void
    {
        // Create directory if it doesn't exist
        $dir = dirname($file_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Check file size and rotate if necessary
        if (file_exists($file_path) && filesize($file_path) > $this->getConfig('log_max_size', 10485760)) {
            $this->rotateLogFile($file_path);
        }
        
        // Write log entry
        file_put_contents($file_path, $content, FILE_APPEND | LOCK_EX);
    }
    
    private function rotateLogFile(string $file_path): void
    {
        $path_info = pathinfo($file_path);
        $base_name = $path_info['dirname'] . '/' . $path_info['filename'];
        $extension = $path_info['extension'] ?? 'log';
        
        // Move existing log files
        $max_files = $this->getConfig('log_max_files', 5);
        for ($i = $max_files - 1; $i >= 1; $i--) {
            $old_file = "{$base_name}.{$i}.{$extension}";
            $new_file = "{$base_name}." . ($i + 1) . ".{$extension}";
            
            if (file_exists($old_file)) {
                if ($i == $max_files - 1) {
                    unlink($old_file); // Delete oldest file
                } else {
                    rename($old_file, $new_file);
                }
            }
        }
        
        // Move current file to .1
        rename($file_path, "{$base_name}.1.{$extension}");
    }
    
    private function getUserInfo(): array
    {
        $user = $this->getCurrentUser();
        return [
            'id' => $user['id'] ?? null,
            'username' => $user['username'] ?? 'guest',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
    }
    
    private function getRequestInfo(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1'
        ];
    }
    
    private function getStackTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $formatted_trace = [];
        
        foreach ($trace as $i => $frame) {
            if ($i < 2) continue; // Skip logger methods
            
            $formatted_trace[] = sprintf(
                "%s:%d %s%s%s()",
                $frame['file'] ?? 'unknown',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? 'unknown'
            );
        }
        
        return $formatted_trace;
    }
    
    private function storeInDatabase(array $entry): void
    {
        try {
            // Check if database logging is enabled
            if (!$this->getConfig('database_logging', true)) {
                return;
            }
            
            // Try to get database connection
            $db = $this->getDatabaseConnection();
            if (!$db) {
                return; // Silent fail if no database connection
            }
            
            $db_name = defined('DB_NAME') ? DB_NAME : '';
            
            $sql = "INSERT INTO logs (level, category, message, context, user_id, ip_address, user_agent, url, method, execution_time, memory_usage, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $entry['level'],
                $entry['channel'],
                $entry['message'],
                json_encode($entry['context'], JSON_UNESCAPED_UNICODE),
                $entry['user']['id'],
                $entry['user']['ip'],
                $entry['user']['user_agent'],
                $entry['request']['uri'],
                $entry['request']['method'],
                0, // Will be filled by query execution tracking
                $entry['memory'],
                $entry['timestamp']
            ];
            
            $db->query($sql, $params, $db_name);
        } catch (Exception $e) {
            // Silent fail to prevent log loops
            error_log("Failed to store log in database: " . $e->getMessage());
        }
    }
    
    private function sendCriticalAlert(array $entry): void
    {
        try {
            $subject = sprintf(
                "[CRITICAL] %s - %s",
                $_SERVER['HTTP_HOST'] ?? 'Database Manager',
                $entry['message']
            );
            
            $body = sprintf(
                "Critical error occurred:\n\n" .
                "Level: %s\n" .
                "Channel: %s\n" .
                "Message: %s\n" .
                "User: %s (%s)\n" .
                "IP: %s\n" .
                "URL: %s\n" .
                "Time: %s\n\n" .
                "Context: %s\n\n" .
                "Stack Trace:\n%s",
                $entry['level'],
                $entry['channel'],
                $entry['message'],
                $entry['user']['username'],
                $entry['user']['id'] ?? 'N/A',
                $entry['user']['ip'],
                $entry['request']['uri'],
                $entry['timestamp'],
                json_encode($entry['context'], JSON_PRETTY_PRINT),
                implode("\n", $entry['trace'])
            );
            
            $this->sendNotification($subject, $body, 'critical');
        } catch (Exception $e) {
            error_log("Failed to send critical alert: " . $e->getMessage());
        }
    }
    
    private function sendNotification(string $subject, string $body, string $priority = 'normal'): void
    {
        // Email notification
        if ($this->getConfig('email_notifications', false)) {
            try {
                $mailer = $this->getEmailManager();
                if ($mailer) {
                    $mailer->sendAlert($subject, $body, $priority);
                }
            } catch (Exception $e) {
                error_log("Email alert failed: " . $e->getMessage());
            }
        }
        
        // SMS notification for critical errors
        if ($priority === 'critical' && $this->getConfig('sms_notifications', false)) {
            try {
                $sms = $this->getSMSManager();
                if ($sms) {
                    $sms->sendAlert(substr($subject . ": " . $body, 0, 160));
                }
            } catch (Exception $e) {
                error_log("SMS alert failed: " . $e->getMessage());
            }
        }
    }
    
    // Helper methods for dependency injection
    
    private function getCurrentUser(): array
    {
        if (function_exists('getCurrentUser')) {
            return getCurrentUser();
        }
        
        // Fallback for session-based user info
        if (isset($_SESSION['user_id'])) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'] ?? 'user'
            ];
        }
        
        return [];
    }
    
    private function getDatabaseConnection()
    {
        if (class_exists('DatabaseManager')) {
            return DatabaseManager::getInstance();
        }
        
        // Fallback to direct PDO connection
        try {
            $host = $this->getConfig('db_host', 'localhost');
            $dbname = $this->getConfig('db_name', '');
            $username = $this->getConfig('db_user', '');
            $password = $this->getConfig('db_password', '');
            
            if (!empty($dbname)) {
                $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $pdo;
            }
        } catch (Exception $e) {
            error_log("Database connection failed: " . $e->getMessage());
        }
        
        return null;
    }
    
    private function getEmailManager()
    {
        if (class_exists('EmailManager')) {
            return new EmailManager();
        }
        return null;
    }
    
    private function getSMSManager()
    {
        if (class_exists('SMSManager')) {
            return new SMSManager();
        }
        return null;
    }
    
    private function getSettingsManager()
    {
        if (class_exists('SettingsManager')) {
            return SettingsManager::getInstance();
        }
        return null;
    }
    
    private function getConfig(string $key, $default = null)
    {
        // Try to get from settings manager first
        $settings_manager = $this->getSettingsManager();
        if ($settings_manager) {
            try {
                return $settings_manager->get($key, $default);
            } catch (Exception $e) {
                // Fall back to defaults
            }
        }
        
        // Try constants
        $constant_name = strtoupper('LOG_' . $key);
        if (defined($constant_name)) {
            return constant($constant_name);
        }
        
        // Use defaults
        return $this->defaults[$key] ?? $default;
    }
    
    // PSR-3 Logger Interface Methods
    
    public function emergency(string $message, array $context = []): void
    {
        $this->writeLog('emergency', $message, $context);
    }
    
    public function alert(string $message, array $context = []): void
    {
        $this->writeLog('alert', $message, $context);
    }
    
    public function critical(string $message, array $context = []): void
    {
        $this->writeLog('critical', $message, $context);
    }
    
    public function error(string $message, array $context = []): void
    {
        $this->writeLog('error', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void
    {
        $this->writeLog('warning', $message, $context);
    }
    
    public function notice(string $message, array $context = []): void
    {
        $this->writeLog('notice', $message, $context);
    }
    
    public function info(string $message, array $context = []): void
    {
        $this->writeLog('info', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void
    {
        $this->writeLog('debug', $message, $context);
    }
    
    // Channel-specific methods
    
    public function security(string $level, string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context, 'security');
    }
    
    public function query(string $level, string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context, 'query');
    }
    
    public function performance(string $level, string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context, 'performance');
    }
    
    public function api(string $level, string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context, 'api');
    }
    
    public function backup(string $level, string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context, 'backup');
    }
    
    /**
     * Get log entries for web interface
     */
    public function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        try {
            $db = $this->getDatabaseConnection();
            if (!$db) {
                return [];
            }
            
            $where_conditions = ['1=1'];
            $params = [];
            
            // Sanitize filters
            $sanitized_filters = $this->sanitizeFilters($filters);
            
            if (!empty($sanitized_filters['level'])) {
                $where_conditions[] = 'level = ?';
                $params[] = $sanitized_filters['level'];
            }
            
            if (!empty($sanitized_filters['category'])) {
                $where_conditions[] = 'category = ?';
                $params[] = $sanitized_filters['category'];
            }
            
            if (!empty($sanitized_filters['user_id'])) {
                $where_conditions[] = 'user_id = ?';
                $params[] = $sanitized_filters['user_id'];
            }
            
            if (!empty($sanitized_filters['start_date'])) {
                $where_conditions[] = 'created_at >= ?';
                $params[] = $sanitized_filters['start_date'];
            }
            
            if (!empty($sanitized_filters['end_date'])) {
                $where_conditions[] = 'created_at <= ?';
                $params[] = $sanitized_filters['end_date'];
            }
            
            if (!empty($sanitized_filters['search'])) {
                $where_conditions[] = 'message LIKE ?';
                $params[] = '%' . $sanitized_filters['search'] . '%';
            }
            
            $sql = "SELECT l.*, u.username 
                    FROM logs l 
                    LEFT JOIN users u ON l.user_id = u.id 
                    WHERE " . implode(' AND ', $where_conditions) . "
                    ORDER BY l.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $db_name = defined('DB_NAME') ? DB_NAME : '';
            $stmt = $db->query($sql, $params, $db_name);
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Failed to fetch logs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean old log entries
     */
    public function cleanupLogs(): void
    {
        try {
            $retention_days = $this->getConfig('log_retention_days', 90);
            $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
            
            $db = $this->getDatabaseConnection();
            if (!$db) {
                return;
            }
            
            $db_name = defined('DB_NAME') ? DB_NAME : '';
            $sql = "DELETE FROM logs WHERE created_at < ?";
            $stmt = $db->query($sql, [$cutoff_date], $db_name);
            
            $deleted_count = $stmt->rowCount();
            $this->info("Log cleanup completed", ['deleted_entries' => $deleted_count]);
            
        } catch (Exception $e) {
            $this->error("Log cleanup failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get log statistics
     */
    public function getLogStats(string $period = '24h'): array
    {
        try {
            $db = $this->getDatabaseConnection();
            if (!$db) {
                return [];
            }
            
            // Define intervals using switch for PHP compatibility
            $interval = 'INTERVAL 24 HOUR'; // Default
            switch ($period) {
                case '1h':
                    $interval = 'INTERVAL 1 HOUR';
                    break;
                case '24h':
                    $interval = 'INTERVAL 24 HOUR';
                    break;
                case '7d':
                    $interval = 'INTERVAL 7 DAY';
                    break;
                case '30d':
                    $interval = 'INTERVAL 30 DAY';
                    break;
            }
            
            $sql = "SELECT 
                        level,
                        category,
                        COUNT(*) as count,
                        MIN(created_at) as first_occurrence,
                        MAX(created_at) as last_occurrence
                    FROM logs 
                    WHERE created_at >= DATE_SUB(NOW(), {$interval})
                    GROUP BY level, category
                    ORDER BY count DESC";
            
            $db_name = defined('DB_NAME') ? DB_NAME : '';
            $stmt = $db->query($sql, [], $db_name);
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Failed to get log stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Sanitize filter inputs to prevent SQL injection
     */
    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];
        
        foreach ($filters as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = trim($value);
            } elseif (is_int($value)) {
                $sanitized[$key] = (int)$value;
            } elseif (is_array($value)) {
                $sanitized[$key] = array_map('trim', $value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}
?>