<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'data';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'error');
    redirect('index.php');
}

$db = new Database($selected_db);
$tables = $db->getTables();
$current_table = $_GET['table'] ?? $_POST['table'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? 'browse';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

/**
 * Handle record insertion / Gestione inserimento record
 */
if ($action === 'insert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST['data'] ?? [];
    $table = sanitize($_POST['table'] ?? '');
    
    if ($table && !empty($data)) {
        try {
            $fields = array_keys($data);
            $placeholders = array_fill(0, count($fields), '?');
            
            $sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            $db->query($sql, array_values($data));
            
            showMessage('Record inserted successfully!', 'success');
            redirect("data.php?table=" . urlencode($table));
        } catch (Exception $e) {
            showMessage('Error inserting record: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle record update / Gestione aggiornamento record
 */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST['data'] ?? [];
    $table = sanitize($_POST['table'] ?? '');
    $primary_key = $_POST['primary_key'] ?? '';
    $primary_value = $_POST['primary_value'] ?? '';
    
    if ($table && !empty($data) && $primary_key && $primary_value) {
        try {
            $set_clauses = [];
            $values = [];
            
            foreach ($data as $field => $value) {
                $set_clauses[] = "`$field` = ?";
                $values[] = $value;
            }
            
            $values[] = $primary_value;
            $sql = "UPDATE `$table` SET " . implode(', ', $set_clauses) . " WHERE `$primary_key` = ?";
            
            $db->query($sql, $values);
            showMessage('Record updated successfully!', 'success');
            redirect("data.php?table=" . urlencode($table));
        } catch (Exception $e) {
            showMessage('Error updating record: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle record deletion / Gestione eliminazione record
 */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $table = sanitize($_POST['table'] ?? '');
    $primary_key = $_POST['primary_key'] ?? '';
    $primary_value = $_POST['primary_value'] ?? '';
    
    if ($table && $primary_key && $primary_value) {
        try {
            $sql = "DELETE FROM `$table` WHERE `$primary_key` = ?";
            $db->query($sql, [$primary_value]);
            
            showMessage('Record deleted successfully!', 'success');
            redirect("data.php?table=" . urlencode($table));
        } catch (Exception $e) {
            showMessage('Error deleting record: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Get table data and structure / Recupera dati e struttura della tabella
 */
$table_data = [];
$table_structure = [];
$total_rows = 0;
$primary_key = '';

if ($current_table) {
    try {
        // Get table structure
        $table_structure = $db->getTableStructure($current_table);
        
        // Find primary key
        foreach ($table_structure as $field) {
            if ($field['Key'] === 'PRI') {
                $primary_key = $field['Field'];
                break;
            }
        }
        
        // Get total row count
        $stmt = $db->query("SELECT COUNT(*) as total FROM `$current_table`");
        $total_rows = $stmt->fetch()['total'];
        
        // Get table data with pagination
        if ($action === 'browse') {
            $stmt = $db->query("SELECT * FROM `$current_table` LIMIT $per_page OFFSET $offset");
            $table_data = $stmt->fetchAll();
        }
        
        // Get specific record for editing
        if ($action === 'edit') {
            $edit_id = $_GET['id'] ?? '';
            if ($edit_id && $primary_key) {
                $stmt = $db->query("SELECT * FROM `$current_table` WHERE `$primary_key` = ?", [$edit_id]);
                $edit_record = $stmt->fetch();
            }
        }
        
    } catch (Exception $e) {
        showMessage('Error fetching table data: ' . $e->getMessage(), 'error');
        $current_table = '';
    }
}

$total_pages = $current_table ? ceil($total_rows / $per_page) : 0;
$page_title = 'Data';
$page_heading = 'Data';
include 'includes/header.php';
?>

        
        <div class="db-selector">
            <h3>Current Database: <strong><?= sanitize($selected_db) ?></strong></h3>
        </div>

        <!-- Table Selection / Selezione Tabella -->
        <?php if (empty($current_table)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">📂 Select a Table</div>
                        <div class="card-subtitle">Choose a table to browse, insert or edit data</div>
                    </div>
                </div>
                <?php if (!empty($tables)): ?>
                    <div class="grid grid-3">
                        <?php foreach ($tables as $table): ?>
                            <div class="card text-center">
                                <h4><?= sanitize($table) ?></h4>
                                <div class="mt-2">
                                    <a href="data.php?table=<?= urlencode($table) ?>" class="btn">📋 Browse Data</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        No tables found in this database. <a href="tables.php">Create a table first</a>.
                    </div>
                <?php endif; ?>
            </div>
        
        <?php elseif ($action === 'insert'): ?>
            <!-- Insert Form / Modulo Inserimento -->
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">➕ Insert Record - <?= sanitize($current_table) ?></div>
                        <div class="card-subtitle">Add a new entry to the database table</div>
                    </div>
                    <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost btn-sm">← Back</a>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <input type="hidden" name="table" value="<?= sanitize($current_table) ?>">
                    
                    <div class="grid grid-2">
                        <?php foreach ($table_structure as $field): ?>
                            <?php if ($field['Extra'] !== 'auto_increment'): ?>
                                <div class="form-group">
                                    <label class="form-label">
                                        <?= sanitize($field['Field']) ?>
                                        <?php if ($field['Null'] === 'NO'): ?>
                                            <span class="text-danger">*</span>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if (strpos($field['Type'], 'text') !== false): ?>
                                        <textarea name="data[<?= sanitize($field['Field']) ?>]" 
                                                  class="form-textarea" 
                                                  placeholder="<?= sanitize($field['Type']) ?>"
                                                  <?= $field['Null'] === 'NO' ? 'required' : '' ?>></textarea>
                                    <?php elseif (strpos($field['Type'], 'date') !== false || strpos($field['Type'], 'time') !== false): ?>
                                        <input type="datetime-local" 
                                               name="data[<?= sanitize($field['Field']) ?>]" 
                                               class="form-input"
                                               <?= $field['Null'] === 'NO' ? 'required' : '' ?>>
                                    <?php else: ?>
                                        <input type="text" 
                                               name="data[<?= sanitize($field['Field']) ?>]" 
                                               class="form-input" 
                                               placeholder="<?= sanitize($field['Type']) ?>"
                                               <?= $field['Null'] === 'NO' ? 'required' : '' ?>>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-success">Insert Record</button>
                        <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost">Cancel</a>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'edit' && isset($edit_record)): ?>
            <!-- Edit Form / Modulo Modifica -->
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">✏️ Edit Record - <?= sanitize($current_table) ?></div>
                        <div class="card-subtitle">Modify existing record values</div>
                    </div>
                    <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost btn-sm">← Back</a>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="table" value="<?= sanitize($current_table) ?>">
                    <input type="hidden" name="primary_key" value="<?= sanitize($primary_key) ?>">
                    <input type="hidden" name="primary_value" value="<?= sanitize($edit_record[$primary_key]) ?>">
                    
                    <div class="grid grid-2">
                        <?php foreach ($table_structure as $field): ?>
                            <div class="form-group">
                                <label class="form-label">
                                    <?= sanitize($field['Field']) ?>
                                    <?php if ($field['Key'] === 'PRI'): ?>
                                        <span class="text-warning">(Primary Key)</span>
                                    <?php endif; ?>
                                    <?php if ($field['Null'] === 'NO'): ?>
                                        <span class="text-danger">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($field['Extra'] === 'auto_increment'): ?>
                                    <input type="text" value="<?= sanitize($edit_record[$field['Field']]) ?>" class="form-input" readonly>
                                <?php elseif (strpos($field['Type'], 'text') !== false): ?>
                                    <textarea name="data[<?= sanitize($field['Field']) ?>]" 
                                              class="form-textarea" 
                                              <?= $field['Null'] === 'NO' ? 'required' : '' ?>><?= sanitize($edit_record[$field['Field']]) ?></textarea>
                                <?php elseif (strpos($field['Type'], 'date') !== false || strpos($field['Type'], 'time') !== false): ?>
                                    <input type="datetime-local" 
                                           name="data[<?= sanitize($field['Field']) ?>]" 
                                           class="form-input"
                                           value="<?= date('Y-m-d\TH:i', strtotime($edit_record[$field['Field']])) ?>"
                                           <?= $field['Null'] === 'NO' ? 'required' : '' ?>>
                                <?php else: ?>
                                    <input type="text" 
                                           name="data[<?= sanitize($field['Field']) ?>]" 
                                           class="form-input" 
                                           value="<?= sanitize($edit_record[$field['Field']]) ?>"
                                           <?= $field['Key'] === 'PRI' ? 'readonly' : '' ?>
                                           <?= $field['Null'] === 'NO' ? 'required' : '' ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-warning">Update Record</button>
                        <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost">Cancel</a>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <!-- Browse Data / Sfoglia Dati -->
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">📋 Data in <?= sanitize($current_table) ?></div>
                        <div class="card-subtitle">Browse and manage records for this table</div>
                    </div>
                    <div>
                        <a href="data.php?table=<?= urlencode($current_table) ?>&action=insert" class="btn btn-success btn-sm">+ Insert Record</a>
                    </div>
                </div>

                <!-- Statistics / Statistiche -->
                <div class="stats-grid mb-3">
                    <div class="stat-card primary">
                        <span class="stat-number"><?= number_format($total_rows) ?></span>
                        <span class="stat-label">Total Records</span>
                    </div>
                    <div class="stat-card info">
                        <span class="stat-number"><?= count($table_structure) ?></span>
                        <span class="stat-label">Columns</span>
                    </div>
                    <div class="stat-card success">
                        <span class="stat-number"><?= $page ?></span>
                        <span class="stat-label">Current Page</span>
                    </div>
                    <div class="stat-card warning">
                        <span class="stat-number"><?= $total_pages ?></span>
                        <span class="stat-label">Total Pages</span>
                    </div>
                </div>

                <?php if (!empty($table_data)): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <?php foreach ($table_structure as $field): ?>
                                        <th>
                                            <?= sanitize($field['Field']) ?>
                                            <?php if ($field['Key'] === 'PRI'): ?>
                                                🔑
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($table_data as $row): ?>
                                    <tr>
                                        <?php foreach ($table_structure as $field): ?>
                                            <td>
                                                <?php 
                                                $value = $row[$field['Field']];
                                                if (is_null($value)) {
                                                    echo '<em class="text-muted">NULL</em>';
                                                } elseif (strlen($value) > 50) {
                                                    echo sanitize(substr($value, 0, 50)) . '...';
                                                } else {
                                                    echo sanitize($value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <?php if ($primary_key): ?>
                                                <a href="data.php?table=<?= urlencode($current_table) ?>&action=edit&id=<?= urlencode($row[$primary_key]) ?>" 
                                                   class="btn btn-sm">✏️ Edit</a>
                                                
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Are you sure you want to delete this record?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="table" value="<?= sanitize($current_table) ?>">
                                                    <input type="hidden" name="primary_key" value="<?= sanitize($primary_key) ?>">
                                                    <input type="hidden" name="primary_value" value="<?= sanitize($row[$primary_key]) ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination / Paginaizone -->
                    <?php if ($total_pages > 1): ?>
                        <div class="d-flex justify-between align-center mt-3">
                            <div>
                                Showing records <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $per_page, $total_rows)) ?> of <?= number_format($total_rows) ?>
                            </div>
                            <div>
                                <?php if ($page > 1): ?>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=1" class="btn btn-sm">First</a>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=<?= $page - 1 ?>" class="btn btn-sm">Previous</a>
                                <?php endif; ?>

                                <span class="btn btn-sm" style="background: #f1f5f9; color: #334155;">Page <?= $page ?> of <?= $total_pages ?></span>

                                <?php if ($page < $total_pages): ?>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=<?= $page + 1 ?>" class="btn btn-sm">Next</a>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=<?= $total_pages ?>" class="btn btn-sm">Last</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        <h4>No data found</h4>
                        <p>This table doesn't have any records yet.</p>
                        <a href="data.php?table=<?= urlencode($current_table) ?>&action=insert" class="btn btn-success">Insert the first record</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
<?php include 'includes/footer.php'; ?>

