<?php
require_once 'config.php';

/**
 * Require authentication and validate session / Richiede autenticazione e valida la sessione
 */
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'query';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'error');
    redirect('index.php');
}

$db = new Database($selected_db);
$query = $_POST['query'] ?? $_GET['query'] ?? '';
$results = null;
$error = null;
$execution_time = 0;
$affected_rows = 0;

/**
 * Include logging functions / Funzioni di logging
 */
function writeQueryLog($message, $level = 'INFO')
{
    $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . DIRECTORY_SEPARATOR . 'query.log';
    $timestamp = date('Y-m-d H:i:s');
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db_name = $_SESSION['selected_db'] ?? 'none';

    $log_entry = "[$timestamp] [$level] [query] [DB:$db_name] [IP:$user_ip] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Handle query execution / Gestione esecuzione della query
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($query)) {
    $start_time = microtime(true);

    // Security validation: Block destructive DDL queries for non-admins
    $is_admin = ($_SESSION['role'] ?? 'user') === 'admin';
    $query_upper = strtoupper(trim(preg_replace('/\s+/', ' ', $query)));
    
    $is_destructive = preg_match('/^(DROP|TRUNCATE|ALTER|GRANT|REVOKE)\b/', $query_upper);
    
    if (!$is_admin && $is_destructive) {
        $error = "Access Denied: You do not have permission to execute destructive queries (DROP, TRUNCATE, ALTER, etc.). Please contact an administrator.";
        writeQueryLog("Blocked destructive query attempt: $query", 'SECURITY_BLOCK');
    } else {
        try {
        $stmt = $db->query($query);
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        /**
         * Log successful query execution / Log esecuzione riuscita
         */
        $query_preview = strlen($query) > 100 ? substr($query, 0, 100) . '...' : $query;
        writeQueryLog("Query executed successfully in {$execution_time}ms: $query_preview", 'SUCCESS');

        /**
         * Check if it's a SELECT query / Verifica se è una query SELECT
         */
        $query_type = strtoupper(trim(preg_replace('/\s+/', ' ', $query)));

        if (strpos($query_type, 'SELECT') === 0) {
            $results = $stmt->fetchAll();
            $affected_rows = count($results);
        } elseif (strpos($query_type, 'SHOW') === 0 || strpos($query_type, 'DESCRIBE') === 0 || strpos($query_type, 'DESC') === 0) {
            $results = $stmt->fetchAll();
            $affected_rows = count($results);
        } else {
            $affected_rows = $stmt->rowCount();
            showMessage("Query executed successfully. $affected_rows rows affected.", 'success');
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        // Log query error
        $query_preview = strlen($query) > 100 ? substr($query, 0, 100) . '...' : $query;
        writeQueryLog("Query failed in {$execution_time}ms: $query_preview - Error: $error", 'ERROR');
    }
    }
}

/**
 * Sample queries for quick access / Query di esempio per accesso rapido
 */
$sample_queries = [
    'Show all tables' => "SHOW TABLES;",
    'Show databases' => "SHOW DATABASES;",
    'Show table structure' => "DESCRIBE table_name;",
    'Select all records' => "SELECT * FROM table_name LIMIT 10;",
    'Count records' => "SELECT COUNT(*) as total FROM table_name;",
    'Create table' => "CREATE TABLE example (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n  name VARCHAR(255) NOT NULL,\n  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n);",
    'Insert record' => "INSERT INTO table_name (column1, column2) VALUES ('value1', 'value2');",
    'Update record' => "UPDATE table_name SET column1 = 'new_value' WHERE id = 1;",
    'Delete record' => "DELETE FROM table_name WHERE id = 1;"
];

$tables = $db->getTables();

/**
 * Pre-build autocomplete hint map / Costruisce la mappa per l'autocompletamento
 */
$hint_tables = [];
foreach ($tables as $_ht) {
    try {
        $hint_tables[$_ht] = array_column($db->getTableStructure($_ht), 'Field');
    } catch (Exception $e) {
        $hint_tables[$_ht] = [];
    }
}
$hint_tables_json = json_encode($hint_tables);

$page_title = 'Query';
$page_heading = 'Query';
include 'includes/header.php';
?>

<!-- CodeMirror CSS / Stili CodeMirror -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/material-darker.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.css">
<style>
    .CodeMirror { height: 300px; border-radius: 8px; font-family: 'Consolas', 'Monaco', monospace; margin-top: 5px; }
</style>

<div class="db-selector">
    <h3>Current Database: <strong>
            <?= sanitize($selected_db) ?>
        </strong></h3>
</div>

<div class="grid grid-3">
    <!-- Query Editor / Editor Query -->
    <div class="main-content" style="grid-column: 1 / 3;">
        <!-- AI Assistant / Assistente AI -->
        <div class="card mb-4" style="background: var(--bg-elevated); border: 1px solid var(--border-accent);">
            <div class="card-header d-flex justify-between align-center">
                <h3 class="card-title">🤖 AI Query Assistant</h3>
                <span class="badge badge-info" style="font-size: 0.6rem; opacity: 0.8;"><?= defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : 'AI' ?></span>
            </div>
            <div style="padding: 1rem;">
                <div class="d-flex gap-2">
                    <input type="text" id="ai-prompt" class="form-input" placeholder="E.g., Show me all tables or Find users who registered today..." style="flex: 1;">
                    <button type="button" id="ai-generate-btn" class="btn btn-primary">
                        ✨ Generate SQL
                    </button>
                </div>
                <div id="ai-status" class="text-small mt-2" style="display: none; font-size: 0.75rem;"></div>
                <div id="ai-response-box" class="mt-3 p-3 rounded" style="display: none; background: rgba(0,0,0,0.2); border: 1px solid var(--border-subtle); font-size: 0.85rem; line-height: 1.5; color: var(--text-secondary);">
                    <div class="d-flex justify-between align-center mb-2">
                        <strong style="color: var(--accent-primary);">🤖 AI Analysis</strong>
                        <button type="button" class="btn btn-xs" onclick="document.getElementById('ai-response-box').style.display='none'">✕</button>
                    </div>
                    <div id="ai-response-content" style="white-space: pre-wrap;"></div>
                </div>
            </div>
        </div>

        <h2>SQL Query Editor</h2>

        <form method="POST" id="queryForm">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <div class="form-group">
                <label class="form-label">SQL Query</label>
                <textarea id="sql-editor" name="query" class="form-textarea" rows="12"
                    placeholder="Enter your SQL query here..."><?= sanitize($query) ?></textarea>
            </div>

            <div class="d-flex gap-2 align-center mb-2">
                <button type="submit" class="btn btn-success">▶️ Execute Query</button>
                <button type="button" onclick="clearQuery()" class="btn btn-ghost">🗑️ Clear</button>
                <button type="button" onclick="formatQuery()" class="btn btn-ghost">✨ Format</button>
                <button type="button" onclick="saveQuery()" class="btn btn-ghost">💾 Save</button>
                <select id="saved-queries" class="form-select" style="width: auto;" onchange="loadSavedQuery()">
                    <option value="">Load Saved Query...</option>
                </select>
            </div>

            <div class="text-small text-muted mb-2">
                <strong>Shortcuts:</strong> Ctrl+Enter (Execute), Ctrl+Space (Autocomplete), Ctrl+/ (Comment)
            </div>
        </form>

        <!-- Query Results / Risultati della Query -->
        <?php if ($error): ?>
            <div class="alert alert-danger mt-3">
                <h4>Query Error</h4>
                <p><strong>Execution time:</strong>
                    <?= $execution_time ?> ms
                </p>
                <div class="code-block">
                    <?= sanitize($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($results !== null): ?>
            <script>window.queryResults = <?= json_encode($results) ?>;</script>
            <div class="card mt-4">
                <div class="card-header">
                    <div>
                        <div class="card-title">📋 Query Results</div>
                        <div class="card-subtitle">
                            <?= count($results) ?> rows found in <?= $execution_time ?> ms
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" onclick="exportResults('csv')" class="btn btn-ghost btn-sm">📥 Export CSV</button>
                        <button type="button" onclick="exportResults('json')" class="btn btn-ghost btn-sm">📄 Export JSON</button>
                        <button type="button" onclick="exportResults('excel')" class="btn btn-ghost btn-sm">📊 Export Excel</button>
                    </div>
                </div>

                <?php if (empty($results)): ?>
                    <div class="alert alert-info">No results returned.</div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($results[0]) as $column): ?>
                                        <th>
                                            <?= sanitize($column) ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $value): ?>
                                            <td>
                                                <?php
                                                if (is_null($value)) {
                                                    echo '<em class="text-muted">NULL</em>';
                                                } elseif (strlen($value) > 100) {
                                                    echo sanitize(substr($value, 0, 100)) . '...';
                                                } else {
                                                    echo sanitize($value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($affected_rows > 0): ?>
            <div class="alert alert-success mt-3">
                <h4>Query Executed Successfully</h4>
                <p><strong>Affected rows:</strong>
                    <?= $affected_rows ?>
                </p>
                <p><strong>Execution time:</strong>
                    <?= $execution_time ?> ms
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sample Queries & Tools / Query di esempio e Strumenti -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Quick Actions</h3>
        </div>

        <div class="mb-3">
            <h4>Sample Queries</h4>
            <div class="d-flex" style="flex-direction: column; gap: 0.5rem;">
                <?php foreach ($sample_queries as $name => $sql): ?>
                    <button type="button" class="btn btn-ghost btn-sm text-left"
                        style="text-align: left; font-size: 0.8rem;" 
                        data-query="<?= htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') ?>"
                        onclick="loadQuery(this.getAttribute('data-query'))">
                        <?= sanitize($name) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3">
            <h4>Tables in Database</h4>
            <?php if (!empty($tables)): ?>
                <div style="max-height: 200px; overflow-y: auto;">
                    <?php foreach ($tables as $table): ?>
                        <div class="d-flex justify-between align-center mb-1" style="padding: 0.25rem 0;">
                            <span class="code-inline" style="font-size: 0.8rem;">
                                <?= sanitize($table) ?>
                            </span>
                            <button type="button" class="btn btn-sm" style="padding: 0.2rem 0.4rem; font-size: 0.7rem;"
                                data-table="<?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?>"
                                onclick="loadTableQuery(this.getAttribute('data-table'))">
                                SELECT
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">No tables found</p>
            <?php endif; ?>
        </div>

        <div>
            <h4>SQL Reference</h4>
            <div style="font-size: 0.8rem; line-height: 1.4;">
                <p><strong>Common Commands:</strong></p>
                <ul style="margin: 0; padding-left: 1rem;">
                    <li><code class="code-inline">SHOW TABLES</code> - List tables</li>
                    <li><code class="code-inline">DESC table</code> - Table structure</li>
                    <li><code class="code-inline">SELECT * FROM table</code> - All data</li>
                    <li><code class="code-inline">LIMIT 10</code> - Limit results</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Query History / Cronologia Query -->
<div class="main-content mt-3">
    <h3>Recent Queries</h3>
    <div id="queryHistory">
        <p class="text-muted">Your recent queries will appear here.</p>
    </div>
</div>
</div>

<!-- CodeMirror Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/sql-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/comment/comment.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/selection/active-line.min.js"></script>

<script>
    let sqlEditor;

    document.addEventListener('DOMContentLoaded', function () {
        /**
         * Initialize CodeMirror / Inizializza CodeMirror
         */
        window.sqlEditor = CodeMirror.fromTextArea(document.getElementById('sql-editor'), {
            mode: 'text/x-mysql',
            theme: 'material-darker',
            lineNumbers: true,
            matchBrackets: true,
            autoCloseBrackets: true,
            styleActiveLine: true,
            indentWithTabs: false,
            indentUnit: 2,
            tabSize: 2,
            lineWrapping: true,
            extraKeys: {
                'Ctrl-Enter': function () {
                    document.getElementById('queryForm').submit();
                },
                'Ctrl-S': function() {
                    window.saveQuery();
                },
                'Ctrl-Space': 'autocomplete',
                'Ctrl-/': 'toggleComment'
            },
            hintOptions: {
                tables: <?= $hint_tables_json ?>
            }
            });

    /**
     * Auto-save on change / Salvataggio automatico alla modifica
     */
    sqlEditor.on('change', function () {
        localStorage.setItem('current_query', sqlEditor.getValue());
    });

    /**
     * Load saved query / Carica query salvata
     */
    const savedQuery = localStorage.getItem('current_query');
    if (savedQuery && !sqlEditor.getValue()) {
        sqlEditor.setValue(savedQuery);
    }

    window.displayQueryHistory();
    window.loadSavedQueries();
        });

    window.loadQuery = function(query) {
        if (sqlEditor) {
            sqlEditor.setValue(query);
            sqlEditor.focus();
        }
    };

    window.loadTableQuery = function(table) {
        const sql = `SELECT * FROM \`${table}\` LIMIT 10;`;
        if (sqlEditor) {
            sqlEditor.setValue(sql);
            sqlEditor.focus();
        }
    };

    window.clearQuery = function() {
        if (sqlEditor) {
            sqlEditor.setValue('');
            sqlEditor.focus();
        }
    };

    window.formatQuery = function() {
        if (!sqlEditor) return;

        let query = sqlEditor.getValue().trim();

        // Basic SQL formatting
        query = query.replace(/\s+/g, ' ');
        query = query.replace(/\s*(;)\s*/g, '$1\n');
        query = query.replace(/\b(SELECT|FROM|WHERE|ORDER BY|GROUP BY|HAVING|JOIN|LEFT JOIN|RIGHT JOIN|INNER JOIN|UNION|INSERT INTO|UPDATE|DELETE FROM|CREATE TABLE|ALTER TABLE|DROP TABLE)\b/gi, '\n$1');
        query = query.replace(/\b(AND|OR)\b/gi, '\n  $1');
        query = query.replace(/\n\s*\n/g, '\n');
        query = query.trim();

        sqlEditor.setValue(query);
    };

    window.saveQuery = function() {
        const query = sqlEditor ? sqlEditor.getValue().trim() : '';
        if (!query) {
            alert('Please enter a query to save.');
            return;
        }

        const name = prompt('Enter a name for this query:');
        if (!name) return;

        let savedQueries = JSON.parse(localStorage.getItem('saved_queries') || '{}');
        savedQueries[name] = {
            query: query,
            database: '<?= sanitize($selected_db) ?>',
            created: new Date().toISOString()
        };

        localStorage.setItem('saved_queries', JSON.stringify(savedQueries));
        loadSavedQueries();
        window.showToast('Query saved successfully!', 'success');
    }

    window.loadSavedQuery = function() {
        const select = document.getElementById('saved-queries');
        const queryName = select.value;
        if (!queryName) return;

        const savedQueries = JSON.parse(localStorage.getItem('saved_queries') || '{}');
        if (savedQueries[queryName]) {
            window.loadQuery(savedQueries[queryName].query);
        }
        select.value = '';
    };

    window.loadSavedQueries = function() {
        const savedQueries = JSON.parse(localStorage.getItem('saved_queries') || '{}');
        const select = document.getElementById('saved-queries');

        if (!select) return;

        // Clear existing options except first
        while (select.children.length > 1) {
            select.removeChild(select.lastChild);
        }

        Object.keys(savedQueries).forEach(name => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            select.appendChild(option);
        });
    };

    /**
     * Query history management / Gestione cronologia query
     */
    window.saveQueryToHistory = function(query) {
        if (!query.trim()) return;

        let history = JSON.parse(localStorage.getItem('queryHistory') || '[]');

        // Remove duplicates and add to beginning
        history = history.filter(h => h.query !== query);
        history.unshift({
            query: query,
            timestamp: new Date().toISOString(),
            database: '<?= sanitize($selected_db) ?>'
        });

        // Keep only last 10 queries
        history = history.slice(0, 10);
        localStorage.setItem('queryHistory', JSON.stringify(history));
        window.displayQueryHistory();
    };

    // HTML escape utility
    window.escapeHtml = function(unsafe) {
        return (unsafe || '').toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    };

    // Display query history
    window.displayQueryHistory = function() {
        const history = JSON.parse(localStorage.getItem('queryHistory') || '[]');
        const container = document.getElementById('queryHistory');

        if (!container) return;

        if (history.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent queries found.</p>';
            return;
        }

        let html = '';
        history.forEach((item, index) => {
            const date = new Date(item.timestamp).toLocaleString();
            const truncatedQuery = item.query.length > 100
                ? item.query.substring(0, 100) + '...'
                : item.query;

            html += `
                    <div class="card mb-2">
                        <div class="d-flex justify-between align-center">
                            <div>
                                <code class="code-inline" style="font-size: 0.8rem;">${window.escapeHtml(truncatedQuery)}</code>
                                <div class="text-muted" style="font-size: 0.7rem;">${window.escapeHtml(date)} - ${window.escapeHtml(item.database)}</div>
                            </div>
                            <button type="button" class="btn btn-sm" data-query="${window.escapeHtml(item.query)}" onclick="window.loadQuery(this.getAttribute('data-query'))">
                                Load
                            </button>
                        </div>
                    </div>
                `;
        });

        container.innerHTML = html;
    };

    // Save query to history on form submit
    document.getElementById('queryForm').addEventListener('submit', function (e) {
        if (sqlEditor) {
            const query = sqlEditor.getValue();
            document.querySelector('textarea[name="query"]').value = query;
            saveQueryToHistory(query);
        }
    });

    /** ── AI Assistant Logic / Logica Assistente AI ── */
    const aiPromptInput = document.getElementById('ai-prompt');
    const aiGenerateBtn = document.getElementById('ai-generate-btn');
    const aiStatus = document.getElementById('ai-status');

    aiGenerateBtn?.addEventListener('click', async function() {
        const prompt = aiPromptInput.value.trim();
        if (!prompt) return;

        aiGenerateBtn.disabled = true;
        const originalText = aiGenerateBtn.innerHTML;
        aiGenerateBtn.textContent = '⌛ Thinking...';
        aiStatus.style.display = 'block';
        aiStatus.style.color = 'var(--accent-blue)';
        aiStatus.textContent = 'AI is reading your schema and writing SQL...';

        try {
            const formData = new FormData();
            formData.append('prompt', prompt);

            const response = await fetch('api/ai_query.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (data.success) {
                if (data.is_sql) {
                    sqlEditor.setValue(data.sql);
                    aiStatus.style.color = 'var(--color-success)';
                    aiStatus.textContent = '✅ SQL generated successfully!';
                    document.getElementById('ai-response-box').style.display = 'none';
                } else {
                    document.getElementById('ai-response-box').style.display = 'block';
                    document.getElementById('ai-response-content').textContent = data.content;
                    aiStatus.style.color = 'var(--color-info)';
                    aiStatus.textContent = '✅ Analysis complete!';
                    if (data.sql && data.sql !== data.content) {
                        // If there's SQL inside the explanation, we could potentially extract it
                        // but for now we just show the explanation
                    }
                }
                window.showToast('AI Response Received!', 'success');
                aiPromptInput.value = '';
            } else {
                aiStatus.style.color = 'var(--color-danger)';
                aiStatus.textContent = '❌ Error: ' + data.error;
                window.showToast('AI Error: ' + data.error, 'error');
            }
        } catch (error) {
            aiStatus.style.color = 'var(--color-danger)';
            aiStatus.textContent = '❌ Connection error: ' + error.message;
            window.showToast('Connection failed', 'error');
        } finally {
            aiGenerateBtn.disabled = false;
            aiGenerateBtn.innerHTML = originalText;
            setTimeout(() => { if(aiStatus) aiStatus.style.display = 'none'; }, 8000);
        }
    });

    aiPromptInput?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            aiGenerateBtn.click();
        }
    });

    /** ── Export Logic / Logica Esportazione ── */
    window.exportResults = function(format) {
        if (!window.queryResults || window.queryResults.length === 0) {
            window.showToast('No results to export', 'warning');
            return;
        }

        const data = window.queryResults;
        const filename = `query_export_${new Date().getTime()}`;

        if (format === 'json') {
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            downloadBlob(blob, `${filename}.json`);
        } else if (format === 'csv') {
            const headers = Object.keys(data[0]);
            const csvRows = [
                headers.join(','),
                ...data.map(row => headers.map(fieldName => {
                    const value = row[fieldName] === null ? '' : row[fieldName];
                    return `"${String(value).replace(/"/g, '""')}"`;
                }).join(','))
            ];
            const blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
            downloadBlob(blob, `${filename}.csv`);
        } else if (format === 'excel') {
            if (typeof XLSX === 'undefined') {
                window.showToast('Loading Excel engine...', 'info');
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
                script.onload = () => window.exportResults('excel');
                document.head.appendChild(script);
                return;
            }
            const worksheet = XLSX.utils.json_to_sheet(data);
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, "Query Results");
            XLSX.writeFile(workbook, `${filename}.xlsx`);
            window.showToast(`Exported as ${filename}.xlsx`, 'success');
            return; // writeFile handles download directly
        }
    };

    function downloadBlob(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        window.showToast(`Exported as ${filename}`, 'success');
    }
</script>
<?php include 'includes/footer.php'; ?>