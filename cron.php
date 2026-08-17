<?php
/**
 * Cron script for scheduled database backups
 * This script should be called by the system cron job
 * 
 * Usage:
 *   php cron.php [--db=database_name] [--keep=number_of_backups]
 * 
 * Example cron job (daily at 2 AM):
 *   0 2 * * * /usr/bin/php /path/to/gestionedb/cron.php --keep=7
 */

// Load configuration
require_once __DIR__ . '/config.php';

// Parse command line arguments
$options = getopt('', ['db:', 'keep:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php cron.php [--db=database_name] [--keep=number_of_backups]\n";
    echo "\nOptions:\n";
    echo "  --db       Database name to backup (default: all databases)\n";
    echo "  --keep     Number of backups to keep (default: 7)\n";
    echo "  --help     Show this help message\n";
    exit(0);
}

$database_to_backup = $options['db'] ?? null;
$backups_to_keep = isset($options['keep']) ? (int)$options['keep'] : 7;
$backup_dir = __DIR__ . '/backups';
$log_file = __DIR__ . '/logs/cron.log';

// Ensure backup directory exists
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Ensure log directory exists
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

/**
 * Log message to cron log file
 */
function logMessage($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    echo $log_entry;
}

/**
 * Create a backup of a database
 */
function createDatabaseBackup($db_name, $backup_dir) {
    global $db;
    
    try {
        $db = new Database($db_name);
        $timestamp = date('Y-m-d_H-i-s');
        $backup_file = $backup_dir . '/backup_' . $db_name . '_' . $timestamp . '.sql';
        
        // Use mysqldump for better performance and reliability
        $dump_cmd = sprintf(
            'mysqldump --single-transaction --routines --triggers --events --hex-blob --host=%s --port=%s --user=%s %s 2>&1',
            escapeshellarg(DB_HOST),
            escapeshellarg(3306),
            escapeshellarg(DB_USER),
            escapeshellarg($db_name)
        );
        
        // Execute mysqldump
        $output = [];
        $return_code = 0;
        exec($dump_cmd . ' > ' . escapeshellarg($backup_file), $output, $return_code);
        
        if ($return_code !== 0) {
            // If mysqldump failed, try using PHP method
            return createBackupViaPHP($db, $backup_file);
        }
        
        return [
            'success' => true,
            'file' => $backup_file,
            'size' => filesize($backup_file)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Create backup using PHP (fallback method)
 */
function createBackupViaPHP($db, $backup_file) {
    try {
        $db_name = $db->getCurrentDatabase();
        
        // Get all tables
        $tables = $db->getTables();
        
        $sql_content = "-- GestioneDb Backup\n";
        $sql_content .= "-- Database: " . $db_name . "\n";
        $sql_content .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Get CREATE DATABASE statement
        $sql_content .= "CREATE DATABASE IF NOT EXISTS `" . $db_name . "`;\n\n";
        $sql_content .= "USE `" . $db_name . "`;\n\n";
        
        foreach ($tables as $table) {
            // Get table structure
            $structure = $db->getTableStructure($table);
            
            // Build CREATE TABLE statement
            $columns = [];
            foreach ($structure as $column) {
                $col_def = "  `" . $column['Field'] . "` " . $column['Type'];
                if ($column['Null'] === 'NO') {
                    $col_def .= ' NOT NULL';
                }
                if (!empty($column['Default']) && $column['Default'] !== 'NULL') {
                    $col_def .= " DEFAULT " . $column['Default'];
                }
                if (!empty($column['Key'])) {
                    $col_def .= " " . $column['Key'];
                }
                if (!empty($column['Extra'])) {
                    $col_def .= " " . $column['Extra'];
                }
                $columns[] = $col_def;
            }
            
            $sql_content .= "CREATE TABLE `" . $table . "` (\n";
            $sql_content .= implode(",\n", $columns) . "\n);\n\n";
            
            // Get table data
            $data = $db->query("SELECT * FROM `" . $table . "`");
            $rows = $data->fetchAll();
            
            if (!empty($rows)) {
                $sql_content .= "INSERT INTO `" . $table . "` VALUES\n";
                
                foreach ($rows as $index => $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    
                    $sql_content .= "(" . implode(', ', $values) . ")";
                    if ($index < count($rows) - 1) {
                        $sql_content .= ",\n";
                    } else {
                        $sql_content .= ";\n\n";
                    }
                }
            }
        }
        
        // Write to file
        if (file_put_contents($backup_file, $sql_content) !== false) {
            return [
                'success' => true,
                'file' => $backup_file,
                'size' => strlen($sql_content)
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Failed to write backup file'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Clean up old backups
 */
function cleanupOldBackups($backup_dir, $keep_count) {
    $backups = glob($backup_dir . '/*.sql');
    
    if (count($backups) <= $keep_count) {
        return 0;
    }
    
    // Sort by modification time (oldest first)
    usort($backups, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    // Delete oldest backups
    $deleted = 0;
    $to_delete = array_slice($backups, 0, count($backups) - $keep_count);
    
    foreach ($to_delete as $backup) {
        if (unlink($backup)) {
            $deleted++;
        }
    }
    
    return $deleted;
}

/**
 * Get list of all databases
 */
function getAllDatabases() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $stmt = $pdo->query("SHOW DATABASES");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Filter out system databases
        $system_dbs = ['information_schema', 'mysql', 'performance_schema', 'sys', 'db_manager_auth'];
        return array_diff($databases, $system_dbs);
        
    } catch (Exception $e) {
        logMessage("Error getting databases: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// Main execution
logMessage("Starting scheduled backup process", 'INFO');

$databases_to_backup = [];

if ($database_to_backup) {
    // Backup specific database
    $databases_to_backup = [$database_to_backup];
} else {
    // Backup all databases
    $databases_to_backup = getAllDatabases();
}

$successful_backups = 0;
$failed_backups = 0;

foreach ($databases_to_backup as $db_name) {
    logMessage("Creating backup for database: $db_name", 'INFO');
    
    $result = createDatabaseBackup($db_name, $backup_dir);
    
    if ($result['success']) {
        $successful_backups++;
        logMessage("Backup created successfully: " . basename($result['file']) . 
                   " (" . formatBytes($result['size']) . ")", 'INFO');
    } else {
        $failed_backups++;
        logMessage("Backup failed for $db_name: " . $result['error'], 'ERROR');
    }
}

// Clean up old backups
$deleted_count = cleanupOldBackups($backup_dir, $backups_to_keep);
if ($deleted_count > 0) {
    logMessage("Deleted $deleted_count old backup(s)", 'INFO');
}

// Summary
logMessage("Backup process completed. Success: $successful_backups, Failed: $failed_backups", 'INFO');

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

exit($failed_backups > 0 ? 1 : 0);