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
        
        // Search logic
        $search_term = trim($_GET['search'] ?? '');
        $where_sql = '';
        $where_params = [];
        
        if ($search_term !== '') {
            $search_clauses = [];
            foreach ($table_structure as $field) {
                // Using LIKE on all columns dynamically
                $search_clauses[] = "`{$field['Field']}` LIKE ?";
                $where_params[] = "%{$search_term}%";
            }
            if (!empty($search_clauses)) {
                $where_sql = " WHERE " . implode(' OR ', $search_clauses);
            }
        }
        
        // Get total row count
        $stmt = $db->query("SELECT COUNT(*) as total FROM `$current_table`" . $where_sql, $where_params);
        $total_rows = $stmt->fetch()['total'];
        
        // Get table data with pagination
        if ($action === 'browse') {
            $stmt = $db->query("SELECT * FROM `$current_table`" . $where_sql . " LIMIT $per_page OFFSET $offset", $where_params);
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
$page_title = __('data');
$page_heading = __('data');
include 'includes/header.php';
?>

        
        <div class="db-selector">
            <h3><?= __('current_database') ?>: <strong><?= sanitize($selected_db) ?></strong></h3>
        </div>

        <!-- Table Selection / Selezione Tabella -->
        <?php if (empty($current_table)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">📂 <?= __('select_table') ?></div>
                        <div class="card-subtitle"><?= __('choose_table') ?></div>
                    </div>
                </div>
                <?php if (!empty($tables)): ?>
                    <div class="grid grid-3">
                        <?php foreach ($tables as $table): ?>
                            <div class="card text-center">
                                <h4><?= sanitize($table) ?></h4>
                                <div class="mt-2">
                                    <a href="data.php?table=<?= urlencode($table) ?>" class="btn">📋 <?= __('browse') ?></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <?= __('no_tables_found') ?> <a href="tables.php"><?= __('create_table') ?></a>.
                    </div>
                <?php endif; ?>
            </div>
        
        <?php elseif ($action === 'insert'): ?>
            <!-- Insert Form / Modulo Inserimento -->
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">➕ <?= __('insert_record') ?> — <?= sanitize($current_table) ?></div>
                        <div class="card-subtitle"><?= __('add_new_entry') ?></div>
                    </div>
                    <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost btn-sm">← <?= __('back') ?></a>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="insert">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
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
                        <button type="submit" class="btn btn-success"><?= __('insert_record') ?></button>
                        <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost"><?= __('cancel') ?></a>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'edit' && isset($edit_record)): ?>
            <!-- Edit Form / Modulo Modifica -->
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">✏️ <?= __('edit_record') ?> — <?= sanitize($current_table) ?></div>
                        <div class="card-subtitle"><?= __('modify_record') ?></div>
                    </div>
                    <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost btn-sm">← <?= __('back') ?></a>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
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
                        <button type="submit" class="btn btn-warning"><?= __('update_record') ?></button>
                        <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost"><?= __('cancel') ?></a>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <!-- Browse Data / Sfoglia Dati -->
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">📋 <?= __('data_in') ?> <?= sanitize($current_table) ?></div>
                        <div class="card-subtitle"><?= __('browse_manage') ?></div>
                    </div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <form method="GET" action="data.php" style="display:flex; gap:0.25rem;">
                            <input type="hidden" name="table" value="<?= htmlspecialchars($current_table) ?>">
                            <input type="text" name="search" class="form-input" placeholder="<?= __('search', 'Search') ?>..." value="<?= htmlspecialchars($search_term ?? '') ?>" style="padding: 0.25rem 0.5rem; font-size:0.85rem;">
                            <button type="submit" class="btn btn-primary btn-sm">🔍</button>
                            <?php if ($search_term !== ''): ?>
                                <a href="data.php?table=<?= urlencode($current_table) ?>" class="btn btn-ghost btn-sm" title="<?= __('clear_search', 'Clear Search') ?>">✖</a>
                            <?php endif; ?>
                        </form>
                        <a href="data.php?table=<?= urlencode($current_table) ?>&action=insert" class="btn btn-success btn-sm">+ <?= __('insert_record') ?></a>
                    </div>
                </div>

                <!-- Statistics / Statistiche -->
                <div class="stats-grid mb-3">
                    <div class="stat-card primary">
                        <span class="stat-number"><?= number_format($total_rows) ?></span>
                        <span class="stat-label"><?= __('total_records') ?></span>
                    </div>
                    <div class="stat-card info">
                        <span class="stat-number"><?= count($table_structure) ?></span>
                        <span class="stat-label"><?= __('columns') ?></span>
                    </div>
                    <div class="stat-card success">
                        <span class="stat-number"><?= $page ?></span>
                        <span class="stat-label"><?= __('current_page') ?></span>
                    </div>
                    <div class="stat-card warning">
                        <span class="stat-number"><?= $total_pages ?></span>
                        <span class="stat-label"><?= __('total_pages') ?></span>
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
                                    <th><?= __('actions') ?></th>
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
                                                   class="btn btn-sm">✏️ <?= __('edit') ?></a>
                                                
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Are you sure you want to delete this record?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
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
                        <?php $search_qs = ($search_term !== '') ? '&search=' . urlencode($search_term) : ''; ?>
                        <div class="d-flex justify-between align-center mt-3">
                            <div style="font-size: 0.85rem; opacity: 0.8;">
                                <?= __('showing_records', 'Showing records') ?> <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $per_page, $total_rows)) ?> <?= __('of', 'of') ?> <?= number_format($total_rows) ?>
                            </div>
                            <div style="display: flex; gap: 0.25rem; align-items: center;">
                                <?php if ($page > 1): ?>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=1<?= $search_qs ?>" class="btn btn-sm btn-ghost">«</a>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=<?= $page - 1 ?><?= $search_qs ?>" class="btn btn-sm btn-outline">‹</a>
                                <?php endif; ?>

                                <span class="btn btn-sm" style="background: var(--accent-primary); color: #fff; pointer-events: none; border: none; padding: 0.2rem 0.6rem;"><?= $page ?></span>

                                <?php if ($page < $total_pages): ?>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=<?= $page + 1 ?><?= $search_qs ?>" class="btn btn-sm btn-outline">›</a>
                                    <a href="data.php?table=<?= urlencode($current_table) ?>&page=<?= $total_pages ?><?= $search_qs ?>" class="btn btn-sm btn-ghost">»</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info text-center">
                        <h4><?= __('no_data_found') ?></h4>
                        <p><?= __('table_empty') ?></p>
                        <a href="data.php?table=<?= urlencode($current_table) ?>&action=insert" class="btn btn-success"><?= __('insert_record') ?></a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
<?php include 'includes/footer.php'; ?>

