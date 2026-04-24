<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'tables';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'warning');
    redirect('index.php');
}

$db = new Database($selected_db);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$table_name = $_GET['table'] ?? $_POST['table'] ?? '';

/**
 * Handle table creation / Gestione creazione tabella
 */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = sanitize($_POST['table_name'] ?? '');
    $fields = $_POST['fields'] ?? [];
    
    if ($table_name && !empty($fields)) {
        try {
            $sql = "CREATE TABLE `" . str_replace('`', '``', $table_name) . "` (";
            $field_definitions = [];
            
            foreach ($fields as $field) {
                if (!empty($field['name']) && !empty($field['type'])) {
                    $field_def = "`" . str_replace('`', '``', sanitize($field['name'])) . "` " . strtoupper(sanitize($field['type']));
                    
                    // Add length/values
                    if (!empty($field['length'])) {
                        $field_def .= "(" . sanitize($field['length']) . ")";
                    }
                    
                    // Add attributes
                    if (!empty($field['null']) && $field['null'] === 'no') {
                        $field_def .= " NOT NULL";
                    }
                    
                    if (!empty($field['auto_increment'])) {
                        $field_def .= " AUTO_INCREMENT";
                    }
                    
                    if (!empty($field['default']) && $field['default'] !== '') {
                        $field_def .= " DEFAULT '" . sanitize($field['default']) . "'";
                    }
                    
                    $field_definitions[] = $field_def;
                }
            }
            
            // Add primary key if specified
            $primary_indices = $_POST['primary'] ?? [];
            $pk_fields = [];
            foreach ($primary_indices as $idx) {
                if (isset($fields[$idx]['name']) && !empty($fields[$idx]['name'])) {
                    $pk_fields[] = "`" . str_replace('`', '``', sanitize($fields[$idx]['name'])) . "`";
                }
            }
            if (!empty($pk_fields)) {
                $field_definitions[] = "PRIMARY KEY (" . implode(', ', $pk_fields) . ")";
            }
            
            $sql .= implode(', ', $field_definitions) . ")";
            
            $db->query($sql);
            showMessage("Table '$table_name' created successfully!", 'success');
            redirect('tables.php');
        } catch (Exception $e) {
            showMessage("Error creating table: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle table deletion / Gestione eliminazione tabella
 */
if ($action === 'drop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = sanitize($_POST['table_name'] ?? '');
    if ($table_name) {
        try {
            $db->query("DROP TABLE `" . str_replace('`', '``', $table_name) . "`");
            showMessage("Table '$table_name' deleted successfully!", 'success');
            redirect('tables.php');
        } catch (Exception $e) {
            showMessage("Error deleting table: " . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle table truncation / Gestione svuotamento tabella
 */
if ($action === 'truncate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table_name = sanitize($_POST['table_name'] ?? '');
    if ($table_name) {
        try {
            $db->query("TRUNCATE TABLE `" . str_replace('`', '``', $table_name) . "`");
            showMessage("Table '$table_name' truncated successfully!", 'success');
            redirect('tables.php');
        } catch (Exception $e) {
            showMessage("Error truncating table: " . $e->getMessage(), 'error');
        }
    }
}

$tables = $db->getTables();
$table_structure = [];
$table_info = [];

if ($action === 'structure' && $table_name) {
    try {
        $table_structure = $db->getTableStructure($table_name);
        
        // Get table info
        $stmt = $db->query("SELECT COUNT(*) as row_count FROM `" . str_replace('`', '``', $table_name) . "`");
        $table_info = $stmt->fetch();
    } catch (Exception $e) {
        showMessage("Error fetching table structure: " . $e->getMessage(), 'error');
        $action = 'list';
    }
}

$page_title = 'Tables';
$page_heading = 'Tables Management';
$page_description = 'Manage tables in the selected database';
include 'includes/header.php';
?>


<?php if ($action === 'create'): ?>
    <!-- Create Table Form / Modulo Creazione Tabella -->
    <div class="page-header">
        <div class="page-header-info">
            <h1 class="page-title">⚙️ Create New Table</h1>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="tables.php" class="btn btn-sm btn-ghost">← Back to Tables</a>
        </div>
    </div>

    <form method="POST" id="createTableForm">
        <input type="hidden" name="action" value="create">
        
        <div class="card mb-4">
            <div class="form-group mb-0">
                <label class="form-label">Table Name</label>
                <input type="text" name="table_name" class="form-input" placeholder="e.g. users, products..." required>
            </div>
        </div>

        <h4 class="mb-3 text-secondary">Columns Definition</h4>
        
        <div id="fieldsContainer">
            <div class="field-row card mb-3">
                <div class="grid grid-3 mb-3">
                    <div class="form-group">
                        <label class="form-label">Field Name</label>
                        <input type="text" name="fields[0][name]" class="form-input" placeholder="id" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="fields[0][type]" class="form-select" required>
                            <option value="">Select Type</option>
                            <option value="INT" selected>INT</option>
                            <option value="VARCHAR">VARCHAR</option>
                            <option value="TEXT">TEXT</option>
                            <option value="DATE">DATE</option>
                            <option value="DATETIME">DATETIME</option>
                            <option value="TIMESTAMP">TIMESTAMP</option>
                            <option value="DECIMAL">DECIMAL</option>
                            <option value="FLOAT">FLOAT</option>
                            <option value="BOOLEAN">BOOLEAN</option>
                            <option value="BLOB">BLOB</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Length/Values</label>
                        <input type="text" name="fields[0][length]" class="form-input" placeholder="11 (e.g.)">
                    </div>
                </div>
                
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Default Value</label>
                        <input type="text" name="fields[0][default]" class="form-input" placeholder="(No Default)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Options</label>
                        <div class="d-flex gap-3 mt-2">
                            <label><input type="checkbox" name="fields[0][null]" value="no" checked> NOT NULL</label>
                            <label><input type="checkbox" name="fields[0][auto_increment]" value="1" checked> Auto Increment</label>
                            <label><input type="checkbox" name="primary[]" value="0" checked> Primary Key</label>
                        </div>
                    </div>
                </div>
                
                <div class="text-right mt-2">
                    <button type="button" class="btn btn-danger btn-xs remove-field" onclick="removeField(this)">Remove Field</button>
                </div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="d-flex justify-between align-center mt-3">
            <button type="button" class="btn btn-outline" onclick="addField()">+ Add Another Field</button>
            <button type="submit" class="btn btn-success">✅ Create Table</button>
        </div>
    </form>

<?php elseif ($action === 'structure' && $table_name): ?>
    <!-- Table Structure View / Vista Struttura Tabella -->
    <div class="page-header">
        <div class="page-header-info">
            <h1 class="page-title">🔍 Table Structure: <?= sanitize($table_name) ?></h1>
            <p class="page-description">View schema details and column definitions</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="data.php?table=<?= urlencode($table_name) ?>" class="btn btn-sm btn-primary">📋 Browse Data</a>
            <a href="tables.php" class="btn btn-sm btn-ghost">← Back to Tables</a>
        </div>
    </div>

    <div class="stats-grid grid-3 mb-4">
        <div class="stat-card primary">
            <span class="stat-number"><?= $table_info['row_count'] ?? 0 ?></span>
            <span class="stat-label">Total Rows</span>
        </div>
        <div class="stat-card success">
            <span class="stat-number"><?= count($table_structure) ?></span>
            <span class="stat-label">Total Columns</span>
        </div>
    </div>

    <div class="card">
        <div class="table-container m-0 border-0">
            <table class="table">
                <thead>
                    <tr>
                        <th>Field Name</th>
                        <th>Data Type</th>
                        <th>Allow Nulls</th>
                        <th>Key Constraint</th>
                        <th>Default Value</th>
                        <th>Extra Info</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_structure as $field): ?>
                        <tr>
                            <td><strong class="text-primary"><?= sanitize($field['Field']) ?></strong></td>
                            <td><code class="code-inline"><?= sanitize($field['Type']) ?></code></td>
                            <td><?= $field['Null'] === 'YES' ? '<span class="badge badge-success">YES</span>' : '<span class="badge badge-danger">NO</span>' ?></td>
                            <td>
                                <?php if($field['Key'] === 'PRI'): ?>
                                    <span class="badge badge-warning">PRIMARY</span>
                                <?php elseif($field['Key'] === 'UNI'): ?>
                                    <span class="badge badge-info">UNIQUE</span>
                                <?php elseif($field['Key'] === 'MUL'): ?>
                                    <span class="badge badge-primary">INDEX</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $field['Default'] !== null ? '<code class="code-inline">'.sanitize($field['Default']).'</code>' : '<span class="text-muted">NULL</span>' ?></td>
                            <td><?= sanitize($field['Extra']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- Table List View / Vista Elenco Tabelle -->
    <div class="page-header">
        <div class="page-header-info">
            <h1 class="page-title">📊 Database Tables</h1>
            <p class="page-description">Overview of all tables inside <strong class="text-primary"><?= sanitize($selected_db) ?></strong></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="tables.php?action=create" class="btn btn-sm btn-success">➕ Create Table</a>
        </div>
    </div>

    <div class="card">
        <?php if (!empty($tables)): ?>
            <div class="table-container m-0 border-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th class="text-right">Quick Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                            <tr>
                                <td>
                                    <strong class="fs-sm"><?= sanitize($table) ?></strong>
                                </td>
                                <td class="text-right">
                                    <div class="d-flex gap-2 justify-end">
                                        <a href="data.php?table=<?= urlencode($table) ?>" class="btn btn-xs btn-primary">📋 Browse</a>
                                        <a href="tables.php?action=structure&table=<?= urlencode($table) ?>" class="btn btn-xs btn-outline">🔍 Structure</a>
                                        
                                        <form method="POST" style="display: inline;" data-confirm="Are you sure you want to empty table '<?= sanitize($table) ?>'? All data will be lost!">
                                            <input type="hidden" name="action" value="truncate">
                                            <input type="hidden" name="table_name" value="<?= sanitize($table) ?>">
                                            <button type="submit" class="btn btn-warning btn-xs">🗑 Empty</button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;" data-confirm="Are you sure you want to DROP table '<?= sanitize($table) ?>'? This CANNOT be undone!">
                                            <input type="hidden" name="action" value="drop">
                                            <input type="hidden" name="table_name" value="<?= sanitize($table) ?>">
                                            <button type="submit" class="btn btn-danger btn-xs">❌ Drop</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-state-icon">📊</span>
                <div class="empty-state-title">No tables found</div>
                <p class="empty-state-text">This database doesn't have any tables yet.</p>
                <div class="mt-4">
                    <a href="tables.php?action=create" class="btn btn-success">Create your first table</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
/**
 * Inject custom JS / Iniezione JS personalizzato
 */
$extra_js = <<<JS
let fieldIndex = 1;

window.addField = function() {
    const container = document.getElementById('fieldsContainer');
    const fieldRow = document.createElement('div');
    fieldRow.className = 'field-row card mb-3';
    
    // Animate new elements
    fieldRow.style.opacity = '0';
    fieldRow.style.transform = 'translateY(10px)';
    fieldRow.style.transition = 'all 0.3s ease';

    fieldRow.innerHTML = `
        <div class="grid grid-3 mb-3">
            <div class="form-group">
                <label class="form-label">Field Name</label>
                <input type="text" name="fields[\${fieldIndex}][name]" class="form-input" placeholder="col_name" required>
            </div>
            <div class="form-group">
                <label class="form-label">Type</label>
                <select name="fields[\${fieldIndex}][type]" class="form-select" required>
                    <option value="">Select Type</option>
                    <option value="INT">INT</option>
                    <option value="VARCHAR">VARCHAR</option>
                    <option value="TEXT">TEXT</option>
                    <option value="DATE">DATE</option>
                    <option value="DATETIME">DATETIME</option>
                    <option value="TIMESTAMP">TIMESTAMP</option>
                    <option value="DECIMAL">DECIMAL</option>
                    <option value="FLOAT">FLOAT</option>
                    <option value="BOOLEAN">BOOLEAN</option>
                    <option value="BLOB">BLOB</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Length/Values</label>
                <input type="text" name="fields[\${fieldIndex}][length]" class="form-input" placeholder="255">
            </div>
        </div>
        
        <div class="grid grid-2">
            <div class="form-group">
                <label class="form-label">Default Value</label>
                <input type="text" name="fields[\${fieldIndex}][default]" class="form-input" placeholder="(No Default)">
            </div>
            <div class="form-group">
                <label class="form-label">Options</label>
                <div class="d-flex gap-3 mt-2">
                    <label><input type="checkbox" name="fields[\${fieldIndex}][null]" value="no"> NOT NULL</label>
                    <label><input type="checkbox" name="fields[\${fieldIndex}][auto_increment]" value="1"> Auto Increment</label>
                    <label><input type="checkbox" name="primary[]" value="\${fieldIndex}"> Primary Key</label>
                </div>
            </div>
        </div>
        
        <div class="text-right mt-2">
            <button type="button" class="btn btn-danger btn-xs remove-field" onclick="removeField(this)">Remove Field</button>
        </div>
    `;
    
    container.appendChild(fieldRow);
    
    // Trigger animation
    setTimeout(() => {
        fieldRow.style.opacity = '1';
        fieldRow.style.transform = 'translateY(0)';
    }, 10);
    
    fieldIndex++;
};

window.removeField = function(button) {
    const fieldRows = document.querySelectorAll('.field-row');
    if (fieldRows.length > 1) {
        const row = button.closest('.field-row');
        row.style.opacity = '0';
        row.style.transform = 'translateY(-10px)';
        setTimeout(() => row.remove(), 300);
    } else {
        alert('At least one column is required.');
    }
};
JS;
?>
<?php include 'includes/footer.php'; ?>
