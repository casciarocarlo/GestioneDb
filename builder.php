<?php
require_once 'config.php';

if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php'); exit;
}

$current_page = 'builder';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'warning');
    redirect('index.php');
}

$db = new Database($selected_db);
$tables = $db->getTables();

/**
 * Get table structure for query builder / Recupera struttura tabelle per il costruttore di query
 */
$table_structures = [];
foreach ($tables as $table) {
    try {
        $structure = $db->getTableStructure($table);
        $table_structures[$table] = $structure;
    } catch (Exception $e) {}
}

/**
 * Handle query execution from builder / Gestione dell'esecuzione query dal costruttore visuale
 */
$query_result = null;
$query_error = null;
$execution_time = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['built_query'])) {
    $built_query = trim($_POST['built_query']);
    $start_time = microtime(true);

    try {
        $stmt = $db->query($built_query);
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        if (stripos($built_query, 'SELECT') === 0 || stripos($built_query, 'SHOW') === 0 || stripos($built_query, 'DESCRIBE') === 0) {
            $query_result = ['affected_rows' => $stmt->rowCount()];
        }

        /**
         * Log success / Log operazione riuscita
         */
        $log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
        @mkdir($log_dir, 0755, true);
        $log_entry = "[" . date('Y-m-d H:i:s') . "] [SUCCESS] [builder] [DB:$selected_db] [IP:" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "] " . substr(str_replace(["\r","\n"],' ',$built_query), 0, 100) . PHP_EOL;
        @file_put_contents($log_dir . DIRECTORY_SEPARATOR . 'query.log', $log_entry, FILE_APPEND | LOCK_EX);

    } catch (Exception $e) {
        $query_error = $e->getMessage();
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
    }
}

$page_title = 'Visual Query Builder';
$page_heading = 'Query Builder';
$page_description = 'Drag & Drop query composer for <strong class="text-primary">' . htmlspecialchars($selected_db) . '</strong>';
include 'includes/header.php';
?>

<style>
/* ── Builder Layout ── */
.builder-layout {
    display: grid;
    grid-template-columns: 260px 1fr 300px;
    gap: 1.5rem;
    height: 70vh;
    min-height: 600px;
    margin-bottom: 2rem;
}

@media (max-width: 1100px) {
    .builder-layout { grid-template-columns: 260px 1fr; height: auto; }
    .query-panel { grid-column: 1 / -1; }
}

@media (max-width: 768px) {
    .builder-layout { grid-template-columns: 1fr; }
}

/* ── Panel Shared ── */
.panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-lg);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.panel-header {
    background: rgba(255,255,255,0.03);
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border-subtle);
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.panel-content {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    scrollbar-width: thin;
}

/* ── Tables Panel ── */
.table-item {
    background: var(--bg-elevated);
    border: 1px solid var(--border-default);
    border-radius: var(--radius-md);
    margin-bottom: 0.75rem;
    overflow: hidden;
    transition: border-color var(--transition-fast);
}
.table-item:hover { border-color: var(--accent-primary); }

.table-name {
    padding: 0.5rem 0.75rem;
    background: rgba(255,255,255,0.02);
    font-size: 0.8rem;
    font-weight: 600;
    cursor: grab;
    border-bottom: 1px solid var(--border-subtle);
}
.table-name:active { cursor: grabbing; }

.column-item {
    padding: 0.4rem 0.75rem;
    font-size: 0.75rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: grab;
    transition: background var(--transition-fast);
}
.column-item:hover { background: rgba(99,102,241,0.1); }
.column-item:active { cursor: grabbing; }
.column-type { font-family: 'JetBrains Mono', monospace; font-size: 0.65rem; color: var(--text-muted); }

/* ── Canvas Panel ── */
.query-canvas {
    position: relative;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.2);
    border-radius: var(--radius-sm);
    border: 1px dashed var(--border-default);
    overflow: auto;
}

.drop-zone {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 0.9rem;
    transition: all var(--transition-fast);
    z-index: 1;
}
.drop-zone.drag-over { 
    background: rgba(99,102,241,0.1); 
    border-color: var(--accent-primary); 
    color: var(--accent-primary); 
}

.canvas-table {
    position: absolute;
    width: 220px;
    background: var(--bg-elevated);
    border: 1px solid var(--border-default);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    z-index: 10;
    display: flex;
    flex-direction: column;
}
.canvas-table-header {
    padding: 0.5rem 0.75rem;
    background: var(--gradient-primary);
    color: white;
    font-size: 0.8rem;
    font-weight: 600;
    border-radius: calc(var(--radius-md) - 1px) calc(var(--radius-md) - 1px) 0 0;
    cursor: grab;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.canvas-table-header:active { cursor: grabbing; }
.canvas-table-close { cursor: pointer; opacity: 0.8; font-size: 1rem; line-height: 1; }
.canvas-table-close:hover { opacity: 1; }

.canvas-columns { max-height: 200px; overflow-y: auto; }
.canvas-column {
    padding: 0.4rem 0.75rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    cursor: pointer;
    border-bottom: 1px solid var(--border-subtle);
    transition: all var(--transition-fast);
}
.canvas-column:hover { background: rgba(255,255,255,0.03); }
.canvas-column.selected { background: rgba(99,102,241,0.15); color: #a5b4fc; border-left: 2px solid var(--accent-primary); }

/* ── Query Panel Controls ── */
.query-type-selector { display:flex; gap:0.25rem; background:rgba(0,0,0,0.2); padding:0.25rem; border-radius:var(--radius-sm); margin-bottom: 1.25rem; }
.query-type-btn { 
    flex:1; padding:0.4rem; border-radius: var(--radius-sm); border:none; 
    background:transparent; color:var(--text-muted); cursor:pointer; 
    font-size:0.75rem; font-weight:600; transition:all .2s; 
}
.query-type-btn:hover { color:var(--text-primary); }
.query-type-btn.active { background:var(--bg-elevated); color:var(--accent-primary); box-shadow:var(--shadow-sm); }

.control-group { margin-bottom: 1.25rem; }
.control-label { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.5rem; }

.selected-columns { 
    background: rgba(0,0,0,0.2); border: 1px solid var(--border-subtle); 
    border-radius: var(--radius-sm); padding: 0.75rem; min-height: 60px;
    display: flex; flex-wrap: wrap; gap: 0.4rem; font-size: 0.8rem; color: var(--text-muted);
}
.column-chip, .condition-item, .order-item {
    background: var(--bg-elevated); border: 1px solid var(--border-default);
    padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; display: flex; align-items: center; gap: 0.4rem;
}
.column-chip .remove, .condition-item .remove, .order-item .remove {
    color: var(--color-danger); cursor: pointer; font-size: 1rem; line-height: 1; opacity: 0.7;
}
.column-chip .remove:hover { opacity: 1; }

.quick-add { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
.quick-add input, .quick-add select { flex: 1; padding: 0.4rem 0.6rem; font-size: 0.75rem; background: var(--bg-elevated); border: 1px solid var(--border-default); border-radius: var(--radius-sm); color: var(--text-primary); }
.quick-add select { flex: 2; }
.quick-add select:last-of-type { flex: 1; }
.quick-add button { background: var(--bg-elevated); border: 1px solid var(--border-default); color: var(--text-primary); padding: 0 0.75rem; border-radius: var(--radius-sm); cursor: pointer; font-size: 0.75rem; }
.quick-add button:hover { background: var(--accent-primary); color: white; border-color: var(--accent-primary); }

.generated-query { 
    background: #060d1b; color: #a5b4fc; font-family: 'JetBrains Mono', monospace; 
    padding: 1rem; border-radius: var(--radius-sm); border: 1px solid rgba(99,102,241,0.2); 
    white-space: pre-wrap; font-size: 0.78rem; line-height: 1.5;
}

.builder-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 1.5rem; }
.builder-actions .btn-success { grid-column: 1 / -1; }
</style>

<!-- Page Header / Intestazione Pagina -->
<div class="page-header">
    <div class="page-header-info">
        <h1 class="page-title">🔧 Query Builder</h1>
        <p class="page-description">Drag & Drop visual query composer for <strong class="text-primary"><?= htmlspecialchars($selected_db) ?></strong></p>
    </div>
</div>

<div class="builder-layout">
    
    <!-- 1. Tables Panel / 1. Pannello Tabelle -->
    <div class="panel">
        <div class="panel-header">📊 Tables & Columns</div>
        <div class="panel-content">
            <?php foreach ($table_structures as $table_name => $columns): ?>
                <div class="table-item" draggable="true" data-table="<?= sanitize($table_name) ?>">
                    <div class="table-name"><?= sanitize($table_name) ?></div>
                    <div class="table-columns">
                        <?php foreach ($columns as $column): ?>
                            <div class="column-item" draggable="true" data-table="<?= sanitize($table_name) ?>" data-column="<?= sanitize($column['Field']) ?>" data-type="<?= sanitize($column['Type']) ?>">
                                <span><?= sanitize($column['Field']) ?></span>
                                <span class="column-type"><?= sanitize($column['Type']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 2. Canvas Panel / 2. Pannello Canvas -->
    <div class="panel">
        <div class="panel-header">🎨 Canvas</div>
        <div class="panel-content" style="padding:0; overflow:hidden;">
            <div class="query-canvas" id="queryCanvas">
                <div class="drop-zone" id="dropZone">
                    Drag tables or columns here...
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Query Generation Panel / 3. Pannello Generazione Query -->
    <div class="panel">
        <div class="panel-header">⚙️ Configuration</div>
        <div class="panel-content">
            
            <div class="query-type-selector">
                <button class="query-type-btn active" data-type="SELECT">SELECT</button>
                <button class="query-type-btn" data-type="INSERT">INSERT</button>
                <button class="query-type-btn" data-type="UPDATE">UPDATE</button>
                <button class="query-type-btn" data-type="DELETE">DELETE</button>
            </div>

            <div class="control-group">
                <div class="control-label">Selected Columns</div>
                <div class="selected-columns" id="selectedColumns">
                    <span style="opacity:0.5; align-self:center;">Click on canvas columns</span>
                </div>
            </div>

            <div class="control-group">
                <div class="control-label">Where Conditions</div>
                <div id="conditionsList" style="display:flex; flex-direction:column; gap:0.4rem;"></div>
                <div class="quick-add">
                    <input type="text" id="conditionInput" placeholder="e.g. id = 1">
                    <button onclick="addCondition()">Add</button>
                </div>
            </div>

            <div class="control-group">
                <div class="control-label">Order By</div>
                <div id="orderList" style="display:flex; flex-direction:column; gap:0.4rem;"></div>
                <div class="quick-add">
                    <select id="orderColumn"><option value="">Column...</option></select>
                    <select id="orderDirection">
                        <option value="ASC">ASC</option>
                        <option value="DESC">DESC</option>
                    </select>
                    <button onclick="addOrder()">Add</button>
                </div>
            </div>

            <div class="control-group" style="margin-top:auto;">
                <div class="control-label">Generated SQL</div>
                <div class="generated-query" id="generatedQuery">-- Ready for input</div>
            </div>

            <div class="builder-actions">
                <button onclick="executeQuery()" class="btn btn-success">▶️ Execute Query</button>
                <button onclick="copyQuery()" class="btn btn-ghost">📋 Copy SQL</button>
                <button onclick="clearBuilder()" class="btn btn-ghost">🗑️ Clear All</button>
            </div>

        </div>
    </div>
</div>

<!-- Query Results Section / Sezione Risultati Query -->
<?php if ($query_result !== null || $query_error): ?>
    <div class="card mt-4" id="resultsSection">
        <div class="card-header">
            <h3 class="card-title">Query Results</h3>
        </div>

        <?php if ($query_error): ?>
            <div class="alert alert-danger">
                <div>
                    <strong>Execution Error:</strong><br><?= sanitize($query_error) ?>
                </div>
            </div>
        <?php elseif (isset($query_result['affected_rows'])): ?>
            <div class="alert alert-success">
                Query executed successfully. <?= $query_result['affected_rows'] ?> rows affected. (<?= $execution_time ?>ms)
            </div>
        <?php else: ?>
            <div class="mb-3 text-muted" style="font-size:0.8rem;">
                Returned <strong><?= count($query_result) ?></strong> rows in <?= $execution_time ?>ms.
            </div>
            <div class="table-container m-0">
                <table class="table">
                    <thead>
                        <tr>
                            <?php if (!empty($query_result)): ?>
                                <?php foreach (array_keys($query_result[0]) as $column): ?>
                                    <th><?= sanitize($column) ?></th>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <th>Result</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($query_result)): ?>
                            <tr><td class="text-muted text-center" style="padding: 2rem;">No rows returned.</td></tr>
                        <?php else: ?>
                            <?php foreach ($query_result as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= is_null($value) ? '<em class="text-muted">NULL</em>' : sanitize($value) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>setTimeout(() => document.getElementById('resultsSection').scrollIntoView({behavior:'smooth'}), 100);</script>
<?php endif; ?>

<!-- Hidden Execution Form / Modulo Esecuzione Nascosto -->
<form method="POST" id="queryExecutionForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
    <input type="hidden" name="built_query" id="builtQueryInput">
</form>

<script>
    const tableStructures = <?= json_encode($table_structures) ?>;
    
    let state = {
        type: 'SELECT',
        tables: [],
        columns: [],
        conditions: [],
        orderBy: []
    };

    const UI = {
        canvas: document.getElementById('queryCanvas'),
        dropZone: document.getElementById('dropZone'),
        selectedCols: document.getElementById('selectedColumns'),
        condList: document.getElementById('conditionsList'),
        orderList: document.getElementById('orderList'),
        orderColSel: document.getElementById('orderColumn'),
        generated: document.getElementById('generatedQuery')
    };

    document.addEventListener('DOMContentLoaded', () => {
        initBuilder();
        updateQuery();
    });

    function initBuilder() {
        // Draggable source elements
        document.querySelectorAll('.table-item').forEach(el => {
            el.addEventListener('dragstart', e => {
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', JSON.stringify({type: 'table', name: el.dataset.table}));
            });
        });
        document.querySelectorAll('.column-item').forEach(el => {
            el.addEventListener('dragstart', e => {
                e.stopPropagation();
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', JSON.stringify({type: 'column', table: el.dataset.table, column: el.dataset.column}));
            });
        });

        // Drop zone
        [UI.dropZone, UI.canvas].forEach(el => {
            el.addEventListener('dragover', e => e.preventDefault());
            el.addEventListener('dragenter', e => { e.preventDefault(); UI.dropZone.classList.add('drag-over'); });
            el.addEventListener('dragleave', e => { if (!e.currentTarget.contains(e.relatedTarget)) UI.dropZone.classList.remove('drag-over'); });
            el.addEventListener('drop', handleDrop);
        });

        // Type toggles
        document.querySelectorAll('.query-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.query-type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                state.type = this.dataset.type;
                updateQuery();
            });
        });

        // Condition enter
        document.getElementById('conditionInput').addEventListener('keypress', e => { if(e.key==='Enter') addCondition(); });
    }

    function handleDrop(e) {
        e.preventDefault();
        UI.dropZone.classList.remove('drag-over');
        
        try {
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            const rect = UI.canvas.getBoundingClientRect();
            let x = Math.max(0, e.clientX - rect.left - 20);
            let y = Math.max(0, e.clientY - rect.top - 20);

            if (data.type === 'table') addTable(data.name, x, y);
            if (data.type === 'column') {
                if(!state.tables.find(t=>t.name===data.table)) addTable(data.table, x, y);
                toggleColumn(data.table, data.column, null);
            }
        } catch(e) {}
    }

    function addTable(name, x, y) {
        if (state.tables.find(t => t.name === name)) return;
        UI.dropZone.style.display = 'none';

        const wrapper = document.createElement('div');
        wrapper.className = 'canvas-table';
        wrapper.style.left = x + 'px'; wrapper.style.top = y + 'px';
        wrapper.dataset.table = name;

        let html = `<div class="canvas-table-header"><span>${name}</span><span class="canvas-table-close" onclick="removeTable('${name}')">&times;</span></div>`;
        html += `<div class="canvas-columns">`;
        (tableStructures[name] || []).forEach(col => {
            html += `<div class="canvas-column" data-col="${col.Field}" onclick="toggleColumn('${name}','${col.Field}', this)">${col.Field}</div>`;
        });
        html += `</div>`;
        
        wrapper.innerHTML = html;
        UI.canvas.appendChild(wrapper);

        state.tables.push({name, el: wrapper});
        makeDraggable(wrapper, wrapper.querySelector('.canvas-table-header'));
        updateQuery();
    }

    function removeTable(name) {
        let el = document.querySelector(`.canvas-table[data-table="${name}"]`);
        if(el) el.remove();
        state.tables = state.tables.filter(t => t.name !== name);
        state.columns = state.columns.filter(c => c.table !== name);
        if(state.tables.length === 0) UI.dropZone.style.display = 'flex';
        renderState();
    }

    function toggleColumn(table, colName, element) {
        let idx = state.columns.findIndex(c => c.table === table && c.column === colName);
        if (idx >= 0) {
            state.columns.splice(idx, 1);
            if(element) element.classList.remove('selected');
        } else {
            state.columns.push({table, column: colName});
            if(element) element.classList.add('selected');
        }
        
        // Ensure DOM element is selected if toggled programmatically
        if(!element) {
            let DOMel = document.querySelector(`.canvas-table[data-table="${table}"] .canvas-column[data-col="${colName}"]`);
            if(DOMel) DOMel.classList.add('selected');
        }
        renderState();
    }

    function makeDraggable(el, handle) {
        let active = false, startX, startY, initX, initY;
        handle.addEventListener('mousedown', e => {
            active = true; startX = e.clientX; startY = e.clientY;
            initX = parseInt(el.style.left||0); initY = parseInt(el.style.top||0);
            window._zIndex = (window._zIndex || 100) + 1;
            el.style.zIndex = window._zIndex;
            e.preventDefault();
        });
        document.addEventListener('mousemove', e => {
            if(!active) return;
            el.style.left = Math.max(0, initX + (e.clientX - startX)) + 'px';
            el.style.top = Math.max(0, initY + (e.clientY - startY)) + 'px';
        });
        document.addEventListener('mouseup', () => {
            active = false;
        });
    }

    window.addCondition = function() {
        let inp = document.getElementById('conditionInput');
        if(inp.value.trim()){ state.conditions.push(inp.value.trim()); inp.value = ''; renderState(); }
    };
    window.removeCondition = function(i) { state.conditions.splice(i,1); renderState(); };

    window.addOrder = function() {
        let c = document.getElementById('orderColumn').value, d = document.getElementById('orderDirection').value;
        if(c) { state.orderBy.push({c, d}); document.getElementById('orderColumn').value = ''; renderState(); }
    };
    window.removeOrder = function(i) { state.orderBy.splice(i,1); renderState(); };

    function renderState() {
        // Selected cols
        if (state.columns.length === 0) {
            UI.selectedCols.innerHTML = '<span style="opacity:0.5; align-self:center;">Click on canvas columns</span>';
        } else {
            UI.selectedCols.innerHTML = state.columns.map((c, i) => 
                `<span class="column-chip">${c.table}.${c.column} <span class="remove" onclick="removeColAt(${i})">&times;</span></span>`
            ).join('');
        }

        // Conditions
        UI.condList.innerHTML = state.conditions.map((c, i) => 
            `<div class="condition-item"><span>${escapeHtml(c)}</span><span class="remove" onclick="removeCondition(${i})">&times;</span></div>`
        ).join('');

        // Order
        UI.orderList.innerHTML = state.orderBy.map((o, i) => 
            `<div class="order-item"><span>${escapeHtml(o.c)} ${o.d}</span><span class="remove" onclick="removeOrder(${i})">&times;</span></div>`
        ).join('');

        // Order options
        UI.orderColSel.innerHTML = '<option value="">Column...</option>' + state.columns.map(c => 
            `<option value="${c.table}.${c.column}">${c.table}.${c.column}</option>`
        ).join('');

        updateQuery();
    }

    window.removeColAt = function(idx) {
        let col = state.columns[idx];
        let el = document.querySelector(`.canvas-table[data-table="${col.table}"] .canvas-column[data-col="${col.column}"]`);
        if(el) el.classList.remove('selected');
        state.columns.splice(idx, 1);
        renderState();
    };

    function escapeHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function updateQuery() {
        let q = '';
        if (state.type === 'SELECT') {
            q += 'SELECT ' + (state.columns.length ? state.columns.map(c => `\`${c.table}\`.\`${c.column}\``).join(', ') : '*');
            if (state.tables.length) q += '\nFROM ' + state.tables.map(t => `\`${t.name}\``).join(', ');
            if (state.conditions.length) q += '\nWHERE ' + state.conditions.join('\n  AND ');
            if (state.orderBy.length) q += '\nORDER BY ' + state.orderBy.map(o => `${o.c} ${o.d}`).join(', ');
        } else if (state.type === 'INSERT') {
            let t = state.tables[0]?.name || 'table_name';
            let cols = state.columns.length ? state.columns.map(c => `\`${c.column}\``).join(', ') : 'col1, col2';
            let vals = state.columns.length ? state.columns.map(() => `'?'`).join(', ') : "'?', '?'";
            q += `INSERT INTO \`${t}\` (${cols})\nVALUES (${vals});`;
        } else if (state.type === 'UPDATE') {
            let t = state.tables[0]?.name || 'table_name';
            let sets = state.columns.length ? state.columns.map(c => `\`${c.column}\` = '?'`).join(', ') : 'col1 = \'?\'';
            q += `UPDATE \`${t}\`\nSET ${sets}`;
            if (state.conditions.length) q += '\nWHERE ' + state.conditions.join('\n  AND ');
        } else if (state.type === 'DELETE') {
            let t = state.tables[0]?.name || 'table_name';
            q += `DELETE FROM \`${t}\``;
            if (state.conditions.length) q += '\nWHERE ' + state.conditions.join('\n  AND ');
        }

        UI.generated.textContent = q || '-- Ready for input';
    }

    window.executeQuery = function() {
        let q = UI.generated.textContent.trim();
        if(!q || q.startsWith('--')) { alert("Build a query first."); return; }
        document.getElementById('builtQueryInput').value = q;
        document.getElementById('queryExecutionForm').submit();
    };

    window.copyQuery = function() {
        navigator.clipboard.writeText(UI.generated.textContent).then(() => alert('Copied SQL to clipboard!'));
    };

    window.clearBuilder = function() {
        if(!confirm('Clear entire builder canvas?')) return;
        state.tables.forEach(t => t.el.remove());
        state.tables = []; state.columns = []; state.conditions = []; state.orderBy = [];
        UI.dropZone.style.display = 'flex';
        renderState();
    };

</script>
<?php include 'includes/footer.php'; ?>