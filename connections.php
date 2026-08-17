<?php
require_once 'config.php';

if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php'); exit;
}

$current_page = 'connections';
$page_title   = __('connections', 'My Connections');
$page_heading = __('connections', 'My Connections');
$page_description = __('manage_connections', 'Manage your personal database connections');

$user = getCurrentUser();
$uid  = (int)$user['id'];

/* ─────────────────────────────────────────────────────────────
   Auth DB helper
───────────────────────────────────────────────────────────── */
function getAuthPdo(): PDO {
    $pdo = new PDO(
        "mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME . ";charset=utf8mb4",
        AUTH_DB_USER, AUTH_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Create table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_connections (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            label         VARCHAR(100) NOT NULL,
            driver        ENUM('mysql','pgsql','sqlsrv','sqlite') DEFAULT 'mysql',
            host          VARCHAR(255) DEFAULT 'localhost',
            port          SMALLINT UNSIGNED DEFAULT 3306,
            db_name       VARCHAR(255) NOT NULL,
            db_user       VARCHAR(100) DEFAULT 'root',
            db_pass_enc   TEXT,
            is_active     BOOLEAN DEFAULT TRUE,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    return $pdo;
}

/* ─────────────────────────────────────────────────────────────
   Encryption helpers (AES-256-CBC with fallback to XOR)
───────────────────────────────────────────────────────────── */
function encryptPass(string $pass): string {
    if ($pass === '') return '';
    $key = defined('APP_SECRET') ? APP_SECRET : 'GestioneDb_2025';
    $hashKey = hash('sha256', $key, true);
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($pass, 'aes-256-cbc', $hashKey, OPENSSL_RAW_DATA, $iv);
    return 'v2:' . base64_encode($iv . $ciphertext);
}

function decryptPass(string $enc): string {
    if ($enc === '') return '';
    $key = defined('APP_SECRET') ? APP_SECRET : 'GestioneDb_2025';

    // Check if new v2 format
    if (str_starts_with($enc, 'v2:')) {
        $hashKey = hash('sha256', $key, true);
        $data = base64_decode(substr($enc, 3));
        if ($data === false || strlen($data) < 16) return '';
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $dec = openssl_decrypt($ciphertext, 'aes-256-cbc', $hashKey, OPENSSL_RAW_DATA, $iv);
        return $dec !== false ? $dec : '';
    }

    // Fallback to old XOR method for backward compatibility
    $dec = base64_decode($enc);
    if ($dec === false) return '';
    $out = '';
    for ($i = 0; $i < strlen($dec); $i++) {
        $out .= chr(ord($dec[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
   Driver default ports
───────────────────────────────────────────────────────────── */
function defaultPort(string $driver): int {
    return match($driver) {
        'mysql'  => 3306,
        'pgsql'  => 5432,
        'sqlsrv' => 1433,
        'sqlite' => 0,
        default  => 3306,
    };
}

/* ─────────────────────────────────────────────────────────────
   POST handlers
───────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getAuthPdo();
    $action = $_POST['action'] ?? '';

    /* ── Add / Edit connection ── */
    if ($action === 'save_connection') {
        $conn_id  = (int)($_POST['conn_id'] ?? 0);
        $label    = trim($_POST['label'] ?? '');
        $driver   = $_POST['driver'] ?? 'mysql';
        $host     = trim($_POST['host'] ?? 'localhost');
        $port     = (int)($_POST['port'] ?? defaultPort($driver));
        $db_name  = trim($_POST['db_name'] ?? '');
        $db_user  = trim($_POST['db_user'] ?? '');
        $db_pass  = $_POST['db_pass'] ?? '';

        if (!in_array($driver, ['mysql','pgsql','sqlsrv','sqlite'])) {
            showMessage('Invalid driver.', 'error');
        } elseif (!$label || (!$db_name && $driver !== 'sqlite')) {
            showMessage('Label and database name are required.', 'error');
        } else {
            $enc_pass = encryptValue($db_pass);

            if ($conn_id > 0) {
                // Update — only owner
                // If password left empty keep existing
                if ($db_pass === '') {
                    $existing = $pdo->prepare("SELECT db_pass_enc FROM user_connections WHERE id=? AND user_id=?");
                    $existing->execute([$conn_id, $uid]);
                    $row = $existing->fetch();
                    $enc_pass = $row['db_pass_enc'] ?? '';
                }
                $pdo->prepare("
                    UPDATE user_connections
                    SET label=?, driver=?, host=?, port=?, db_name=?, db_user=?, db_pass_enc=?
                    WHERE id=? AND user_id=?
                ")->execute([$label, $driver, $host, $port, $db_name, $db_user, $enc_pass, $conn_id, $uid]);
                showMessage('Connection updated!', 'success');
            } else {
                $pdo->prepare("
                    INSERT INTO user_connections (user_id, label, driver, host, port, db_name, db_user, db_pass_enc)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([$uid, $label, $driver, $host, $port, $db_name, $db_user, $enc_pass]);
                showMessage('Connection added!', 'success');
            }
            redirect('connections.php');
        }
    }

    /* ── Delete connection ── */
    if ($action === 'delete_connection') {
        $conn_id = (int)($_POST['conn_id'] ?? 0);
        $pdo->prepare("DELETE FROM user_connections WHERE id=? AND user_id=?")->execute([$conn_id, $uid]);
        showMessage('Connection deleted.', 'success');
        redirect('connections.php');
    }

    /* ── Test connection ── */
    if ($action === 'test_connection') {
        $conn_id = (int)($_POST['conn_id'] ?? 0);
        $pdo2 = getAuthPdo();
        $stmt = $pdo2->prepare("SELECT * FROM user_connections WHERE id=? AND user_id=?");
        $stmt->execute([$conn_id, $uid]);
        $conn = $stmt->fetch();

        if ($conn) {
            $result = testConnection($conn);
            showMessage($result['message'], $result['ok'] ? 'success' : 'error');
        }
        redirect('connections.php');
    }

    /* ── Activate connection (set as active session DB) ── */
    if ($action === 'activate_connection') {
        $conn_id = (int)($_POST['conn_id'] ?? 0);
        $pdo2 = getAuthPdo();
        $stmt = $pdo2->prepare("SELECT * FROM user_connections WHERE id=? AND user_id=?");
        $stmt->execute([$conn_id, $uid]);
        $conn = $stmt->fetch();
        if ($conn) {
            $_SESSION['active_connection_id']   = $conn['id'];
            $_SESSION['active_connection_label']= $conn['label'];
            $_SESSION['connection_driver']       = $conn['driver'];
            $_SESSION['connection_host']         = $conn['host'];
            $_SESSION['connection_port']         = $conn['port'];
            $_SESSION['connection_user']         = $conn['db_user'];
$_SESSION['connection_pass'] = decryptValue($conn['db_pass_enc']);
            $_SESSION['selected_db']             = $conn['db_name'];
            showMessage("Connected to «{$conn['label']}»!", 'success');
        }
        redirect('index.php');
    }
}

/* ─────────────────────────────────────────────────────────────
   Test helper
───────────────────────────────────────────────────────────── */
function testConnection(array $conn): array {
    try {
        $driver  = $conn['driver'];
        $pass    = decryptPass($conn['db_pass_enc']);

        if ($driver === 'sqlite') {
            $pdo = new PDO("sqlite:" . $conn['db_name']);
        } elseif ($driver === 'mysql') {
            $pdo = new PDO("mysql:host={$conn['host']};port={$conn['port']};dbname={$conn['db_name']};charset=utf8mb4",
                $conn['db_user'], $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } elseif ($driver === 'pgsql') {
            $pdo = new PDO("pgsql:host={$conn['host']};port={$conn['port']};dbname={$conn['db_name']}",
                $conn['db_user'], $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } elseif ($driver === 'sqlsrv') {
            $pdo = new PDO("sqlsrv:Server={$conn['host']},{$conn['port']};Database={$conn['db_name']}",
                $conn['db_user'], $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            return ['ok' => false, 'message' => 'Unsupported driver.'];
        }
        return ['ok' => true, 'message' => "✅ Connection successful to «{$conn['db_name']}»!"];
    } catch (\Exception $e) {
        return ['ok' => false, 'message' => '❌ Connection failed: ' . $e->getMessage()];
    }
}

/* ─────────────────────────────────────────────────────────────
   Load user connections
───────────────────────────────────────────────────────────── */
$pdo_auth = getAuthPdo();
$stmt = $pdo_auth->prepare("SELECT * FROM user_connections WHERE user_id=? ORDER BY label");
$stmt->execute([$uid]);
$connections = $stmt->fetchAll();

/* Edit mode? */
$edit_conn = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt2 = $pdo_auth->prepare("SELECT * FROM user_connections WHERE id=? AND user_id=?");
    $stmt2->execute([$edit_id, $uid]);
    $edit_conn = $stmt2->fetch();
}

include 'includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-info">
        <h1 class="page-title">🔌 <?= __('connections', 'My Connections') ?></h1>
        <p class="page-description"><?= __('manage_connections', 'Add and manage your personal database connections') ?></p>
    </div>
</div>

<div class="grid grid-2" style="align-items: start;">

    <!-- ── Connection List ─────────────────────────────── -->
    <div>
        <?php if (empty($connections)): ?>
        <div class="card empty-state">
            <div class="card-header">
                <div class="card-title">🔌 <?= __('no_connections', 'No connections yet') ?></div>
            </div>
            <div class="card-body">
                <p class="card-subtitle"><?= __('add_first_connection', 'Use the form to add your first database connection.') ?></p>
            </div>
        </div>
        <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <?php foreach ($connections as $c): ?>
            <?php
                $isActive = isset($_SESSION['active_connection_id']) && $_SESSION['active_connection_id'] == $c['id'];
                $driverColors = [
                    'mysql'  => '#4479A1',
                    'pgsql'  => '#336791',
                    'sqlsrv' => '#CC2927',
                    'sqlite' => '#003B57',
                ];
                $driverIcons = [
                    'mysql'  => '🐬',
                    'pgsql'  => '🐘',
                    'sqlsrv' => '🪟',
                    'sqlite' => '📁',
                ];
                $dc = $driverColors[$c['driver']] ?? '#888';
                $di = $driverIcons[$c['driver']] ?? '🗄️';
            ?>
            <div class="card connection-card" style="border-left: 4px solid <?= $dc ?>; <?= $isActive ? 'box-shadow: 0 0 0 2px var(--accent-primary);' : '' ?>">
                <div class="card-header">
                    <div>
                        <div class="card-title" style="display: flex; align-items: center; gap: 0.5rem;">
                            <?= $di ?> <?= htmlspecialchars($c['label']) ?>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success">● ACTIVE</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-subtitle" style="font-family: monospace; font-size: 0.75rem; margin-top: 0.25rem;">
                            <span style="color: <?= $dc ?>; font-weight: 600;"><?= strtoupper($c['driver']) ?></span>
                            <?php if ($c['driver'] !== 'sqlite'): ?>
                                · <?= htmlspecialchars($c['host']) ?>:<?= $c['port'] ?>
                            <?php endif; ?>
                            · <strong><?= htmlspecialchars($c['db_name']) ?></strong>
                        </div>
                    </div>
                </div>
                <div class="card-body" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <!-- Activate -->
                    <form method="POST">
                        <input type="hidden" name="action" value="activate_connection">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="conn_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-success btn-xs" <?= $isActive ? 'disabled' : '' ?>>
                            ⚡ <?= __('connect', 'Connect') ?>
                        </button>
                    </form>
                    <!-- Test -->
                    <form method="POST">
                        <input type="hidden" name="action" value="test_connection">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="conn_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-xs">🧪 <?= __('test', 'Test') ?></button>
                    </form>
                    <!-- Edit -->
                    <a href="connections.php?edit=<?= $c['id'] ?>" class="btn btn-outline btn-xs">✏️ <?= __('edit', 'Edit') ?></a>
                    <!-- Delete -->
                    <form method="POST" onsubmit="return confirm('Delete this connection?')">
                        <input type="hidden" name="action" value="delete_connection">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                        <input type="hidden" name="conn_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-xs">🗑 <?= __('delete', 'Delete') ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Add / Edit Form ─────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">
                    <?= $edit_conn ? '✏️ ' . __('edit_connection', 'Edit Connection') : '➕ ' . __('add_connection', 'New Connection') ?>
                </div>
                <div class="card-subtitle">
                    <?= __('connection_form_hint', 'Configure host, driver and credentials') ?>
                </div>
            </div>
        </div>

        <form method="POST" id="conn-form">
            <input type="hidden" name="action" value="save_connection">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <input type="hidden" name="conn_id" value="<?= $edit_conn['id'] ?? 0 ?>">

            <!-- Label -->
            <div class="form-group">
                <label class="form-label"><?= __('connection_label', 'Connection Label') ?> *</label>
                <input type="text" name="label" class="form-input"
                       placeholder="e.g. My Production DB"
                       value="<?= htmlspecialchars($edit_conn['label'] ?? '') ?>" required>
            </div>

            <!-- Driver -->
            <div class="form-group">
                <label class="form-label"><?= __('driver', 'Database Driver') ?> *</label>
                <select name="driver" id="driver-select" class="form-select" onchange="updateDriverDefaults(this.value)" required>
                    <option value="mysql"  <?= ($edit_conn['driver'] ?? 'mysql') === 'mysql'  ? 'selected' : '' ?>>🐬 MySQL / MariaDB</option>
                    <option value="pgsql"  <?= ($edit_conn['driver'] ?? '') === 'pgsql'  ? 'selected' : '' ?>>🐘 PostgreSQL</option>
                    <option value="sqlsrv" <?= ($edit_conn['driver'] ?? '') === 'sqlsrv' ? 'selected' : '' ?>>🪟 Microsoft SQL Server</option>
                    <option value="sqlite" <?= ($edit_conn['driver'] ?? '') === 'sqlite' ? 'selected' : '' ?>>📁 SQLite</option>
                </select>
            </div>

            <!-- Host + Port (hidden for SQLite) -->
            <div id="host-port-row" class="grid grid-2" style="gap: 1rem;">
                <div class="form-group">
                    <label class="form-label"><?= __('host', 'Host') ?></label>
                    <input type="text" name="host" id="host-input" class="form-input"
                           placeholder="localhost"
                           value="<?= htmlspecialchars($edit_conn['host'] ?? 'localhost') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('port', 'Port') ?></label>
                    <input type="number" name="port" id="port-input" class="form-input"
                           value="<?= $edit_conn['port'] ?? 3306 ?>" min="1" max="65535">
                </div>
            </div>

            <!-- DB Name (path for SQLite) -->
            <div class="form-group">
                <label class="form-label" id="db-name-label"><?= __('db_name_label', 'Database Name') ?> *</label>
                <input type="text" name="db_name" id="db-name-input" class="form-input"
                       placeholder="my_database"
                       value="<?= htmlspecialchars($edit_conn['db_name'] ?? '') ?>" required>
            </div>

            <!-- User + Pass (hidden for SQLite) -->
            <div id="credentials-row">
                <div class="form-group">
                    <label class="form-label"><?= __('db_user', 'Username') ?></label>
                    <input type="text" name="db_user" class="form-input"
                           placeholder="root"
                           value="<?= htmlspecialchars($edit_conn['db_user'] ?? 'root') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <?= __('password', 'Password') ?>
                        <?= $edit_conn ? '<small style="font-weight:400; opacity:.6">(leave blank to keep existing)</small>' : '' ?>
                    </label>
                    <input type="password" name="db_pass" class="form-input"
                           placeholder="<?= $edit_conn ? '••••••••' : 'password' ?>"
                           autocomplete="new-password">
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">
                    💾 <?= $edit_conn ? __('save_changes', 'Save Changes') : __('add_connection', 'Add Connection') ?>
                </button>
                <?php if ($edit_conn): ?>
                <a href="connections.php" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

</div>

<script>
const driverDefaults = {
    mysql:  { port: 3306,  placeholder: 'my_database',     showHostPort: true,  showCreds: true },
    pgsql:  { port: 5432,  placeholder: 'my_database',     showHostPort: true,  showCreds: true },
    sqlsrv: { port: 1433,  placeholder: 'MyDatabase',      showHostPort: true,  showCreds: true },
    sqlite: { port: 0,     placeholder: '/path/to/db.sqlite', showHostPort: false, showCreds: false },
};

function updateDriverDefaults(driver) {
    const d = driverDefaults[driver] || driverDefaults.mysql;
    document.getElementById('port-input').value = d.port || '';
    document.getElementById('db-name-input').placeholder = d.placeholder;
    document.getElementById('db-name-label').textContent = driver === 'sqlite' ? 'SQLite File Path' : '<?= __('db_name_label', 'Database Name') ?> *';
    document.getElementById('host-port-row').style.display  = d.showHostPort ? '' : 'none';
    document.getElementById('credentials-row').style.display = d.showCreds   ? '' : 'none';
}

// init
updateDriverDefaults(document.getElementById('driver-select').value);
</script>

<?php include 'includes/footer.php'; ?>
