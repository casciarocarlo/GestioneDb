-- Sistema di gestione database avanzato
-- Schema migrations per tutte le nuove tabelle

-- Drop tables se esistono (per debugging)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS api_tokens;
DROP TABLE IF EXISTS backup_codes;
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS backups;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS ip_whitelist;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS chart_configs;
DROP TABLE IF EXISTS saved_queries;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

-- Tabella ruoli
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    permissions JSON NOT NULL DEFAULT '{}',
    is_system BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella utenti
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    avatar VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    is_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255),
    reset_token VARCHAR(255),
    reset_expires DATETIME,
    two_factor_secret VARCHAR(32),
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    failed_login_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME,
    last_ip VARCHAR(45),
    preferred_language VARCHAR(5) DEFAULT 'en',
    timezone VARCHAR(50) DEFAULT 'UTC',
    theme VARCHAR(20) DEFAULT 'light',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_active (is_active),
    INDEX idx_reset_token (reset_token),
    INDEX idx_verification_token (verification_token),
    INDEX idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella relazione utenti-ruoli (many-to-many)
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_by INT,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    
    UNIQUE KEY unique_user_role (user_id, role_id),
    INDEX idx_user_id (user_id),
    INDEX idx_role_id (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella sessioni utenti
CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    payload TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella token API
CREATE TABLE api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    abilities JSON DEFAULT '[]',
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella codici backup 2FA
CREATE TABLE backup_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(16) NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella log sistema
CREATE TABLE logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    category VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context JSON DEFAULT '{}',
    user_id INT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    url VARCHAR(500),
    method VARCHAR(10),
    execution_time FLOAT,
    memory_usage INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_level (level),
    INDEX idx_category (category),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_level_category (level, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella backup
CREATE TABLE backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    database_name VARCHAR(100) NOT NULL,
    type ENUM('manual', 'scheduled', 'automatic') DEFAULT 'manual',
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    file_path VARCHAR(500),
    file_size BIGINT DEFAULT 0,
    compressed_size BIGINT DEFAULT 0,
    compression_ratio DECIMAL(5,2),
    checksum VARCHAR(64),
    storage_location ENUM('local', 's3', 'gdrive', 'ftp') DEFAULT 'local',
    cloud_path VARCHAR(500),
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_by INT,
    error_message TEXT,
    metadata JSON DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_database_name (database_name),
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella task scheduler
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('backup', 'cleanup', 'maintenance', 'report', 'custom') NOT NULL,
    command TEXT,
    parameters JSON DEFAULT '{}',
    schedule_type ENUM('once', 'interval', 'cron', 'daily', 'weekly', 'monthly') NOT NULL,
    schedule_value VARCHAR(100), -- cron expression o numero secondi
    status ENUM('pending', 'running', 'completed', 'failed', 'paused') DEFAULT 'pending',
    priority INT DEFAULT 5, -- 1=high, 10=low
    max_retries INT DEFAULT 3,
    retry_count INT DEFAULT 0,
    timeout_seconds INT DEFAULT 3600,
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL,
    last_output TEXT,
    error_message TEXT,
    execution_time FLOAT,
    created_by INT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_next_run (next_run),
    INDEX idx_is_active (is_active),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella notifiche
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    data JSON DEFAULT '{}',
    read_at TIMESTAMP NULL,
    action_url VARCHAR(500),
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_read_at (read_at),
    INDEX idx_created_at (created_at),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella impostazioni sistema
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) UNIQUE NOT NULL,
    value TEXT,
    type ENUM('string', 'int', 'float', 'boolean', 'json', 'encrypted') DEFAULT 'string',
    category VARCHAR(50) DEFAULT 'general',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE, -- se può essere letto da API pubbliche
    is_system BOOLEAN DEFAULT FALSE, -- se è impostazione di sistema
    validation_rules JSON DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_key_name (key_name),
    INDEX idx_category (category),
    INDEX idx_is_public (is_public),
    INDEX idx_is_system (is_system)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella IP whitelist
CREATE TABLE ip_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    ip_range VARCHAR(50), -- per supportare CIDR
    description VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_ip_address (ip_address),
    INDEX idx_is_active (is_active),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella rate limiting
CREATE TABLE rate_limits (
    id VARCHAR(100) PRIMARY KEY, -- IP:action o user_id:action
    requests INT DEFAULT 0,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP NULL,
    
    INDEX idx_window_start (window_start),
    INDEX idx_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella configurazioni grafici
CREATE TABLE chart_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    type ENUM('line', 'bar', 'pie', 'doughnut', 'area', 'scatter') NOT NULL,
    query_sql TEXT NOT NULL,
    chart_options JSON DEFAULT '{}',
    is_public BOOLEAN DEFAULT FALSE,
    refresh_interval INT DEFAULT 300, -- secondi
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_is_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella query salvate
CREATE TABLE saved_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    query_sql TEXT NOT NULL,
    database_name VARCHAR(100),
    is_public BOOLEAN DEFAULT FALSE,
    folder VARCHAR(100) DEFAULT 'default',
    tags JSON DEFAULT '[]',
    execution_count INT DEFAULT 0,
    last_executed TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_user_id (user_id),
    INDEX idx_database_name (database_name),
    INDEX idx_is_public (is_public),
    INDEX idx_folder (folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserimento dati iniziali

-- Ruoli di sistema
INSERT INTO roles (name, display_name, description, permissions, is_system) VALUES
('super_admin', 'Super Administrator', 'Full system access with all permissions', 
 '{"*": ["*"]}', TRUE),
('admin', 'Administrator', 'Full database management access',
 '{"databases": ["*"], "tables": ["*"], "data": ["*"], "query": ["*"], "backup": ["*"], "users": ["*"], "logs": ["read"]}', TRUE),
('editor', 'Database Editor', 'Can manage data and run queries',
 '{"databases": ["read"], "tables": ["read", "create", "update"], "data": ["*"], "query": ["*"], "backup": ["create"]}', TRUE),
('viewer', 'Database Viewer', 'Read-only access to databases and data',
 '{"databases": ["read"], "tables": ["read"], "data": ["read"], "query": ["read"]}', TRUE);

-- Utente admin di default (password: admin123)
INSERT INTO users (username, email, password_hash, first_name, last_name, is_active, is_verified) VALUES
('admin', 'admin@dbmanager.local', '$2y$12$8K8CQ8OLT5OhSvqK5L7ZaOvEfBfYGBcZFvqvzI9w9L0I9c9Z9y9T8u', 'System', 'Administrator', TRUE, TRUE);

-- Assegna ruolo super_admin all'utente admin
INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES
(1, 1, 1);

-- Impostazioni di sistema iniziali
INSERT INTO settings (key_name, value, type, category, description, is_system) VALUES
('app_name', 'MySQL Database Manager Pro', 'string', 'general', 'Application name', TRUE),
('app_version', '2.0.0', 'string', 'general', 'Application version', TRUE),
('maintenance_mode', 'false', 'boolean', 'system', 'Enable maintenance mode', TRUE),
('backup_retention_days', '30', 'int', 'backup', 'Days to keep backup files', FALSE),
('max_query_execution_time', '300', 'int', 'security', 'Maximum query execution time in seconds', FALSE),
('rate_limit_requests', '100', 'int', 'security', 'Requests per minute per IP', FALSE),
('session_timeout', '3600', 'int', 'security', 'Session timeout in seconds', FALSE),
('two_factor_required', 'false', 'boolean', 'security', 'Require 2FA for all users', FALSE),
('backup_compression', 'true', 'boolean', 'backup', 'Enable backup compression', FALSE),
('log_retention_days', '90', 'int', 'system', 'Days to keep log entries', FALSE);

-- Task di esempio per cleanup automatico
INSERT INTO tasks (name, type, command, schedule_type, schedule_value, created_by, is_active) VALUES
('Daily Log Cleanup', 'cleanup', 'cleanup:logs', 'daily', '02:00', 1, TRUE),
('Weekly Database Optimization', 'maintenance', 'maintenance:optimize', 'weekly', 'sunday 03:00', 1, TRUE);

-- Indici aggiuntivi per performance
CREATE INDEX idx_logs_created_user ON logs (created_at, user_id);
CREATE INDEX idx_backups_status_created ON backups (status, created_at);
CREATE INDEX idx_tasks_next_run_status ON tasks (next_run, status);
CREATE INDEX idx_notifications_user_read ON notifications (user_id, read_at);

-- Views utili
CREATE VIEW user_permissions AS
SELECT 
    u.id as user_id,
    u.username,
    u.email,
    GROUP_CONCAT(r.name) as roles,
    JSON_MERGE_PRESERVE(COALESCE(JSON_OBJECT(), '{}'), r.permissions) as permissions
FROM users u
JOIN user_roles ur ON u.id = ur.user_id
JOIN roles r ON ur.role_id = r.id
WHERE u.is_active = TRUE
GROUP BY u.id;

CREATE VIEW system_stats AS
SELECT 
    (SELECT COUNT(*) FROM users WHERE is_active = TRUE) as active_users,
    (SELECT COUNT(*) FROM logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as logs_24h,
    (SELECT COUNT(*) FROM backups WHERE status = 'completed') as completed_backups,
    (SELECT COUNT(*) FROM tasks WHERE is_active = TRUE) as active_tasks,
    (SELECT COUNT(*) FROM notifications WHERE read_at IS NULL) as unread_notifications;