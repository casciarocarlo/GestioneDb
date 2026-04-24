<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'procedures';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'error');
    redirect('index.php');
}

$db = new Database($selected_db);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$procedure_name = $_GET['procedure'] ?? $_POST['procedure'] ?? '';

/**
 * Logging function / Funzione di logging
 */
function writeProcedureLog($message, $level = 'INFO')
{
    $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . DIRECTORY_SEPARATOR . 'system.log';
    $timestamp = date('Y-m-d H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db_name = $_SESSION['selected_db'] ?? 'none';

    $log_entry = "[$timestamp] [$level] [procedures] [DB:$db_name] [IP:$user_ip] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Get all stored procedures / Ottieni l'elenco di tutte le stored procedure
 */
function getStoredProcedures($db)
{
    try {
        $stmt = $db->query("SHOW PROCEDURE STATUS WHERE Db = ?", [$db->getCurrentDatabase()]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get procedure definition / Ottieni la definizione SQL della procedura
 */
function getProcedureDefinition($db, $procedureName)
{
    try {
        $stmt = $db->query("SHOW CREATE PROCEDURE `$procedureName`");
        $result = $stmt->fetch();
        return $result['Create Procedure'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Get procedure parameters / Ottieni i parametri della procedura
 */
function getProcedureParameters($db, $procedureName)
{
    try {
        $stmt = $db->query("SELECT PARAMETER_NAME, PARAMETER_MODE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH 
                           FROM INFORMATION_SCHEMA.PARAMETERS 
                           WHERE SPECIFIC_SCHEMA = ? AND SPECIFIC_NAME = ? 
                           ORDER BY ORDINAL_POSITION",
            [$db->getCurrentDatabase(), $procedureName]
        );
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Handle procedure creation / Gestione creazione procedura
 */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $proc_name = sanitize($_POST['proc_name'] ?? '');
    $proc_body = $_POST['proc_body'] ?? '';
    $proc_params = $_POST['proc_params'] ?? '';

    if ($proc_name && $proc_body) {
        try {
            // Drop existing procedure if it exists
            try {
                $db->query("DROP PROCEDURE IF EXISTS `$proc_name`");
            } catch (Exception $e) {
                // Ignore if procedure doesn't exist
            }

            // Create the procedure
            $create_sql = "CREATE PROCEDURE `$proc_name`($proc_params)\nBEGIN\n$proc_body\nEND";
            $db->query($create_sql);

            writeProcedureLog("Created procedure: $proc_name", 'SUCCESS');
            showMessage("Procedure '$proc_name' created successfully!", 'success');
            redirect('procedures.php');
        } catch (Exception $e) {
            writeProcedureLog("Failed to create procedure: $proc_name - " . $e->getMessage(), 'ERROR');
            showMessage("Error creating procedure: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle procedure execution / Gestione esecuzione procedura
 */
if ($action === 'execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $proc_name = sanitize($_POST['proc_name'] ?? '');
    $proc_params = $_POST['exec_params'] ?? [];

    if ($proc_name) {
        try {
            $params_str = implode(', ', array_map(function ($p) {
                return is_numeric($p) ? $p : "'$p'";
            }, $proc_params));

            $call_sql = "CALL `$proc_name`($params_str)";
            $stmt = $db->query($call_sql);
            $results = $stmt->fetchAll();

            writeProcedureLog("Executed procedure: $proc_name with params: $params_str", 'INFO');
            showMessage("Procedure executed successfully!", 'success');

            // Store results for display
            $_SESSION['procedure_results'] = $results;
            $_SESSION['executed_procedure'] = $proc_name;

        } catch (Exception $e) {
            writeProcedureLog("Failed to execute procedure: $proc_name - " . $e->getMessage(), 'ERROR');
            showMessage("Error executing procedure: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle procedure deletion / Gestione eliminazione procedura
 */
if ($action === 'drop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $proc_name = sanitize($_POST['proc_name'] ?? '');

    if ($proc_name) {
        try {
            $db->query("DROP PROCEDURE `$proc_name`");
            writeProcedureLog("Dropped procedure: $proc_name", 'INFO');
            showMessage("Procedure '$proc_name' deleted successfully!", 'success');
            redirect('procedures.php');
        } catch (Exception $e) {
            writeProcedureLog("Failed to drop procedure: $proc_name - " . $e->getMessage(), 'ERROR');
            showMessage("Error deleting procedure: " . $e->getMessage(), 'error');
        }
    }
}

$procedures = getStoredProcedures($db);
$procedure_definition = '';
$procedure_parameters = [];

if ($action === 'view' && $procedure_name) {
    $procedure_definition = getProcedureDefinition($db, $procedure_name);
    $procedure_parameters = getProcedureParameters($db, $procedure_name);
}

/**
 * Sample procedures for quick creation / Procedure di esempio per creazione rapida
 */
$sample_procedures = [
    'Simple Select' => [
        'params' => 'IN table_name VARCHAR(64)',
        'body' => "SET @sql = CONCAT('SELECT * FROM ', table_name, ' LIMIT 10');\nPREPARE stmt FROM @sql;\nEXECUTE stmt;\nDEALLOCATE PREPARE stmt;"
    ],
    'Count Records' => [
        'params' => 'IN table_name VARCHAR(64), OUT record_count INT',
        'body' => "SET @sql = CONCAT('SELECT COUNT(*) INTO @count FROM ', table_name);\nPREPARE stmt FROM @sql;\nEXECUTE stmt;\nDEALLOCATE PREPARE stmt;\nSET record_count = @count;"
    ],
    'Insert Log Entry' => [
        'params' => 'IN log_message TEXT, IN log_level VARCHAR(20)',
        'body' => "INSERT INTO logs (message, level, created_at) VALUES (log_message, log_level, NOW());"
    ]
];

/**
 * Get execution results if available / Recupera risultati esecuzione se disponibili
 */
$execution_results = $_SESSION['procedure_results'] ?? null;
$executed_procedure_name = $_SESSION['executed_procedure'] ?? '';
if ($execution_results !== null) {
    unset($_SESSION['procedure_results'], $_SESSION['executed_procedure']);
}

$page_title = 'Stored Procedures';
$page_heading = 'Stored Procedures';
include 'includes/header.php';
?>


<div class="db-selector">
    <h3>Current Database: <strong>
            <?= sanitize($selected_db) ?>
        </strong></h3>
</div>

<?php if ($action === 'create'): ?>
    <!-- Create Procedure Form / Modulo Creazione Procedura -->
    <div class="card mb-4">
        <div class="card-header">
            <div>
                <div class="card-title">⚙️ Create Stored Procedure</div>
                <div class="card-subtitle">Define a new MySQL routine with parameters and logic</div>
            </div>
        </div>

        <form method="POST" id="createProcedureForm">
            <input type="hidden" name="action" value="create">

            <div class="grid grid-2 mb-3">
                <div class="form-group">
                    <label class="form-label">Procedure Name</label>
                    <input type="text" name="proc_name" class="form-input" placeholder="my_procedure" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Sample Procedures</label>
                    <select class="form-select" onchange="loadSampleProcedure(this.value)">
                        <option value="">-- Select Sample --</option>
                        <?php foreach ($sample_procedures as $name => $sample): ?>
                            <option value="<?= sanitize($name) ?>">
                                <?= sanitize($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Parameters</label>
                <input type="text" name="proc_params" id="proc_params" class="form-input"
                    placeholder="IN param1 VARCHAR(255), OUT param2 INT">
                <small class="form-text">Example: IN user_id INT, IN username VARCHAR(50), OUT result_count INT</small>
            </div>

            <div class="form-group">
                <label class="form-label">Procedure Body</label>
                <textarea id="procedure-editor" name="proc_body" class="form-textarea" rows="15"
                    placeholder="-- Your procedure code here&#10;SELECT * FROM users WHERE id = user_id;&#10;SET result_count = FOUND_ROWS();"></textarea>
            </div>

            <button type="submit" class="btn btn-success">⚙️ Create Procedure</button>
        </form>
    </div>

<?php elseif ($action === 'view' && $procedure_name): ?>
    <!-- View Procedure / Visualizza Procedura -->
    <div class="card mb-4">
        <div class="card-header">
            <div>
                <div class="card-title">👁️ Procedure: <?= htmlspecialchars($procedure_name) ?></div>
                <div class="card-subtitle">SQL definition and routine properties</div>
            </div>
        </div>

        <!-- Parameters -->
        <?php if (!empty($procedure_parameters)): ?>
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title">Parameters</h3>
                </div>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Mode</th>
                                <th>Data Type</th>
                                <th>Max Length</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($procedure_parameters as $param): ?>
                                <tr>
                                    <td>
                                        <?= sanitize($param['PARAMETER_NAME']) ?>
                                    </td>
                                    <td>
                                        <span
                                            class="badge <?= $param['PARAMETER_MODE'] === 'IN' ? 'badge-info' : ($param['PARAMETER_MODE'] === 'OUT' ? 'badge-warning' : 'badge-success') ?>">
                                            <?= sanitize($param['PARAMETER_MODE']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= sanitize($param['DATA_TYPE']) ?>
                                    </td>
                                    <td>
                                        <?= $param['CHARACTER_MAXIMUM_LENGTH'] ? sanitize($param['CHARACTER_MAXIMUM_LENGTH']) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Definition -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Definition</h3>
            </div>
            <div class="code-block" style="max-height: 500px; overflow-y: auto;">
                <pre><code><?= sanitize($procedure_definition) ?></code></pre>
            </div>
        </div>
    </div>

<?php elseif ($action === 'execute'): ?>
    <!-- Execute Procedure / Esegui Procedura -->
    <div class="card mb-4">
        <div class="d-flex justify-between align-center mb-3">
            <h2>Execute Procedure:
                <?= sanitize($procedure_name) ?>
            </h2>
            <a href="procedures.php" class="btn btn-ghost">← Back to Procedures</a>
        </div>

        <?php
        $params = getProcedureParameters($db, $procedure_name);
        $in_params = array_filter($params, fn($p) => $p['PARAMETER_MODE'] === 'IN' || $p['PARAMETER_MODE'] === 'INOUT');
        ?>

        <form method="POST">
            <input type="hidden" name="action" value="execute">
            <input type="hidden" name="proc_name" value="<?= sanitize($procedure_name) ?>">

            <?php if (!empty($in_params)): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Input Parameters</h3>
                    </div>
                    <?php foreach ($in_params as $i => $param): ?>
                        <div class="form-group">
                            <label class="form-label">
                                <?= sanitize($param['PARAMETER_NAME']) ?>
                                (
                                <?= sanitize($param['DATA_TYPE']) ?>)
                                <span class="badge badge-info">
                                    <?= sanitize($param['PARAMETER_MODE']) ?>
                                </span>
                            </label>
                            <input type="text" name="exec_params[<?= $i ?>]" class="form-input"
                                placeholder="Enter value for <?= sanitize($param['PARAMETER_NAME']) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-success">▶️ Execute Procedure</button>
        </form>

        <!-- Execution Results -->
        <?php if ($execution_results !== null && $executed_procedure_name === $procedure_name): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h3 class="card-title">Execution Results</h3>
                </div>
                <?php if (empty($execution_results)): ?>
                    <div class="alert alert-info">Procedure executed successfully. No results returned.</div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($execution_results[0]) as $column): ?>
                                        <th>
                                            <?= sanitize($column) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($execution_results as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $value): ?>
                                            <td>
                                                <?= is_null($value) ? '<em class="text-muted">NULL</em>' : sanitize($value) ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- List Procedures / Elenco Procedure -->
    <div class="card mb-4">
        <div class="card-header">
            <div>
                <div class="card-title">⚙️ Stored Procedures</div>
                <div class="card-subtitle">Overview of current MySQL routines and operations</div>
            </div>
        </div>

        <?php if (empty($procedures)): ?>
            <div class="alert alert-info">
                No stored procedures found in database "
                <?= sanitize($selected_db) ?>".
                <a href="procedures.php?action=create">Create your first procedure</a>.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Definer</th>
                            <th>Created</th>
                            <th>Modified</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($procedures as $procedure): ?>
                            <tr>
                                <td><strong>
                                        <?= sanitize($procedure['Name']) ?>
                                    </strong></td>
                                <td>
                                    <?= sanitize($procedure['Type']) ?>
                                </td>
                                <td>
                                    <?= sanitize($procedure['Definer']) ?>
                                </td>
                                <td>
                                    <?= sanitize($procedure['Created']) ?>
                                </td>
                                <td>
                                    <?= sanitize($procedure['Modified']) ?>
                                </td>
                                <td>
                                    <a href="procedures.php?action=view&procedure=<?= urlencode($procedure['Name']) ?>"
                                        class="btn btn-sm">👁️ View</a>
                                    <a href="procedures.php?action=execute&procedure=<?= urlencode($procedure['Name']) ?>"
                                        class="btn btn-success btn-sm">▶️ Execute</a>
                                    <form method="POST" style="display: inline;"
                                        onsubmit="return confirm('Delete procedure <?= sanitize($procedure['Name']) ?>?')">
                                        <input type="hidden" name="action" value="drop">
                                        <input type="hidden" name="proc_name" value="<?= sanitize($procedure['Name']) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Quick Help -->
<div class="card mt-4">
    <div class="card-header">
        <h3 class="card-title">💡 Stored Procedure Tips</h3>
    </div>
    <div class="alert alert-info">
        <ul class="list-unstyled mb-0">
            <li><strong>Parameters:</strong> Use IN for input, OUT for output, INOUT for both</li>
            <li><strong>Variables:</strong> Declare variables with DECLARE var_name DATA_TYPE;</li>
            <li><strong>Control Flow:</strong> Use IF-ELSE, WHILE, LOOP, CASE statements</li>
            <li><strong>Error Handling:</strong> Use DECLARE CONTINUE/EXIT HANDLER</li>
            <li><strong>Results:</strong> Use SELECT to return result sets</li>
        </ul>
    </div>
</div>

<!-- CodeMirror Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js"></script>

<script>
    let procedureEditor;

    // Sample procedures data
    const sampleProcedures = <?= json_encode($sample_procedures) ?>;

    document.addEventListener('DOMContentLoaded', function () {
        // Initialize CodeMirror if editor exists
        const editorElement = document.getElementById('procedure-editor');
        if (editorElement) {
            procedureEditor = CodeMirror.fromTextArea(editorElement, {
                mode: 'text/x-mysql',
                theme: 'material-darker',
                lineNumbers: true,
                matchBrackets: true,
                indentWithTabs: false,
                indentUnit: 4,
                tabSize: 4,
                lineWrapping: true
            });
        }
    });

    function loadSampleProcedure(sampleName) {
        if (!sampleName || !procedureEditor) return;

        const sample = sampleProcedures[sampleName];
        if (sample) {
            document.getElementById('proc_params').value = sample.params;
            procedureEditor.setValue(sample.body);
        }
    }

    // Add confirmation for dangerous actions
    document.addEventListener('DOMContentLoaded', function () {
        const dangerButtons = document.querySelectorAll('.btn-danger');
        dangerButtons.forEach(button => {
            if (!button.onclick && !button.getAttribute('onsubmit')) {
                button.addEventListener('click', function (e) {
                    if (!confirm('Are you sure? This action cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            }
        });
    });
</script>
<?php include 'includes/footer.php'; ?>