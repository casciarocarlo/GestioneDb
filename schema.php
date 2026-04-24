<?php
require_once 'config.php';

if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php'); exit;
}

$current_page = 'schema';
$selected_db  = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'warning');
    redirect('index.php');
}

$db = new Database($selected_db);

/**
 * Build full database schema / Costruisce lo schema completo del database
 */
function getDatabaseSchema(Database $db): array {
    $schema = ['tables' => [], 'relationships' => []];

    try {
        foreach ($db->getTables() as $table) {
            $structure  = $db->getTableStructure($table);
            $row_count  = $db->query("SELECT COUNT(*) AS c FROM `" . str_replace('`','``',$table) . "`")->fetch()['c'];

            try {
                $size_row = $db->query("
                    SELECT ROUND(((data_length + index_length)/1024/1024),2) AS size_mb
                    FROM information_schema.TABLES
                    WHERE table_schema = ? AND table_name = ?
                ", [$db->getCurrentDatabase(), $table])->fetch();
            } catch (Exception $e) { $size_row = ['size_mb' => 0]; }

            $schema['tables'][$table] = [
                'name'      => $table,
                'columns'   => $structure,
                'row_count' => $row_count,
                'size_mb'   => $size_row['size_mb'] ?? 0,
                'foreign_keys' => [],
                'indexes'      => [],
            ];

            // Foreign keys / Chiavi esterne
            try {
                $fks = $db->query("
                    SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [$db->getCurrentDatabase(), $table])->fetchAll();

                $schema['tables'][$table]['foreign_keys'] = $fks;
                foreach ($fks as $fk) {
                    $schema['relationships'][] = [
                        'from_table'  => $table,
                        'from_column' => $fk['COLUMN_NAME'],
                        'to_table'    => $fk['REFERENCED_TABLE_NAME'],
                        'to_column'   => $fk['REFERENCED_COLUMN_NAME'],
                    ];
                }
            } catch (Exception $e) {}

            // Indexes / Indici
            try {
                $idx_raw = $db->query("SHOW INDEX FROM `" . str_replace('`','``',$table) . "`")->fetchAll();
                $grouped = [];
                foreach ($idx_raw as $idx) {
                    $k = $idx['Key_name'];
                    if (!isset($grouped[$k])) $grouped[$k] = ['name'=>$k,'unique'=>!$idx['Non_unique'],'columns'=>[]];
                    $grouped[$k]['columns'][] = $idx['Column_name'];
                }
                $schema['tables'][$table]['indexes'] = array_values($grouped);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}

    return $schema;
}

function gridPositions(array $tables): array {
    $positions = [];
    $cols = max(1, (int)ceil(sqrt(count($tables))));
    $i = 0;
    foreach ($tables as $name => $_) {
        $positions[$name] = ['x' => ($i % $cols) * 320 + 40, 'y' => (int)floor($i / $cols) * 280 + 40];
        $i++;
    }
    return $positions;
}

$schema           = getDatabaseSchema($db);
$table_positions  = gridPositions($schema['tables']);
$total_rows       = array_sum(array_column($schema['tables'], 'row_count'));
$total_size       = round(array_sum(array_column($schema['tables'], 'size_mb')), 2);

$page_title       = 'Schema Viewer';
$page_heading     = 'Schema Viewer';
$page_description = 'Interactive entity-relationship diagram for ' . htmlspecialchars($selected_db);
include 'includes/header.php';
?>

<style>
/**
 * Schema-specific styles (scoped) / Stili specifici dello schema
 */
.schema-controls{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
.schema-container{
    position:relative;width:100%;height:600px;
    background:rgba(8,13,26,.7);border:1px solid var(--border-subtle);
    border-radius:var(--radius-lg);overflow:hidden;cursor:default;
}
.schema-canvas{position:absolute;top:0;left:0;transform-origin:0 0;transition:transform .2s ease;}

/* Table boxes / Box tabelle */
.tb{
    position:absolute;min-width:230px;max-width:300px;
    background:var(--bg-elevated);border:1px solid var(--border-default);
    border-radius:var(--radius-md);box-shadow:var(--shadow-md);
    cursor:grab;user-select:none;z-index:10;
    transition:box-shadow .2s,border-color .2s;
}
.tb:hover{border-color:var(--accent-primary);box-shadow:var(--shadow-lg),0 0 0 1px rgba(99,102,241,.3);}
.tb:active{cursor:grabbing;}
.tb-header{
    padding:.6rem .85rem;
    background:linear-gradient(135deg,rgba(99,102,241,.2),rgba(139,92,246,.15));
    border-bottom:1px solid var(--border-subtle);border-radius:var(--radius-md) var(--radius-md) 0 0;
    display:flex;align-items:center;justify-content:space-between;gap:.5rem;
}
.tb-name{font-size:.82rem;font-weight:700;color:var(--text-primary);}
.tb-meta{font-size:.68rem;color:var(--text-muted);}
.tb-cols{padding:.4rem 0;}
.tb-col{
    display:flex;align-items:center;justify-content:space-between;
    padding:.28rem .85rem;gap:.5rem;font-size:.76rem;
    transition:background var(--transition-fast);
}
.tb-col:hover{background:rgba(255,255,255,.03);}
.col-name{color:var(--text-secondary);font-weight:500;}
.col-type{font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--text-muted);}
.col-flags{display:flex;gap:.25rem;flex-shrink:0;}
.col-flag{
    font-size:.6rem;font-weight:700;padding:.1rem .3rem;
    border-radius:3px;line-height:1.4;
}
.flag-pk{background:rgba(245,158,11,.2);color:#fcd34d;border:1px solid rgba(245,158,11,.3);}
.flag-fk{background:rgba(59,130,246,.2);color:#93c5fd;border:1px solid rgba(59,130,246,.3);}
.flag-uq{background:rgba(16,185,129,.2);color:#6ee7b7;border:1px solid rgba(16,185,129,.3);}
.flag-nn{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.2);}

/* Connection handles / Gestori di connessione */
.col-handle{
    width:10px;height:10px;border-radius:50%;
    background:var(--accent-primary);opacity:0;
    cursor:crosshair;transition:opacity .2s, transform .2s;
}
.tb-col:hover .col-handle{opacity:.7;}
.col-handle:hover{opacity:1 !important;transform:scale(1.3);}
.col-handle.selected{background:var(--color-success);opacity:1;box-shadow:0 0 8px var(--color-success);}

/* Modal enhancement */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:var(--z-modal);display:none;align-items:center;justify-content:center;padding:2rem;}
.modal-overlay.active{display:flex;}
.modal-card{background:var(--bg-surface);border:1px solid var(--border-default);border-radius:var(--radius-lg);width:100%;max-width:500px;box-shadow:var(--shadow-lg);animation:modalIn .3s ease;}
@keyframes modalIn { from{opacity:0;transform:translateY(20px);} to{opacity:1;transform:translateY(0);} }


/* Relationship SVG / Linee di relazione SVG */
.rel-line{stroke:rgba(99,102,241,.6);stroke-width:1.5;stroke-dasharray:5,3;}
#arrowhead polygon{fill:rgba(99,102,241,.8);}

/* Zoom hint / Suggerimento Zoom */
.schema-hint{
    position:absolute;bottom:.75rem;right:.75rem;
    font-size:.72rem;color:var(--text-muted);
    background:rgba(0,0,0,.4);padding:.3rem .6rem;border-radius:4px;
}
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-info">
        <h1 class="page-title">🔗 Visual Schema Editor</h1>
        <p class="page-description">Design and manage your database relations visually for <strong class="text-primary"><?= htmlspecialchars($selected_db) ?></strong></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button onclick="openCreateTableModal()" class="btn btn-sm btn-primary">➕ Create Table</button>
        <a href="tables.php" class="btn btn-sm btn-ghost">📊 Tables</a>
        <a href="query.php"  class="btn btn-sm btn-ghost">💻 Query</a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
    <div class="stat-card primary">
        <span class="stat-number"><?= count($schema['tables']) ?></span>
        <span class="stat-label">Tables</span>
    </div>
    <div class="stat-card info">
        <span class="stat-number"><?= count($schema['relationships']) ?></span>
        <span class="stat-label">Relationships</span>
    </div>
    <div class="stat-card success">
        <span class="stat-number"><?= number_format($total_rows) ?></span>
        <span class="stat-label">Total Records</span>
    </div>
    <div class="stat-card warning">
        <span class="stat-number"><?= $total_size ?> MB</span>
        <span class="stat-label">Total Size</span>
    </div>
</div>

<!-- Controls -->
<div class="schema-controls">
    <div class="d-flex gap-2 flex-wrap">
        <button onclick="schemaReset()" class="btn btn-ghost btn-sm">🔄 Reset Layout</button>
        <button onclick="schemaZoom(1.2)" class="btn btn-ghost btn-sm">＋ Zoom In</button>
        <button onclick="schemaZoom(0.8)" class="btn btn-ghost btn-sm">－ Zoom Out</button>
        <button onclick="schemaToggleRels()" class="btn btn-ghost btn-sm" id="btnToggleRel">🔗 Hide Relationships</button>
        <button onclick="schemaExport()" class="btn btn-success btn-sm">📥 Export JSON</button>
    </div>
    <div class="d-flex gap-3 align-center" style="font-size:.78rem;color:var(--text-muted)">
        <span><span class="col-flag flag-pk">PK</span> Primary Key</span>
        <span><span class="col-flag flag-fk">FK</span> Foreign Key</span>
        <span><span class="col-flag flag-uq">UQ</span> Unique</span>
        <span><span class="col-flag flag-nn">NN</span> Not Null</span>
        <span style="color:rgba(99,102,241,.8)">⏤⏤▶</span> Relationship
    </div>
</div>

<!-- Canvas -->
<div class="schema-container" id="schemaContainer">
    <div class="schema-canvas" id="schemaCanvas">

        <?php foreach ($schema['tables'] as $table_name => $td): ?>
        <div class="tb" id="tb-<?= sanitize($table_name) ?>"
             style="left:<?= $table_positions[$table_name]['x'] ?>px;top:<?= $table_positions[$table_name]['y'] ?>px;"
             data-table="<?= sanitize($table_name) ?>">

            <div class="tb-header">
                <div>
                    <span class="tb-name">📋 <?= sanitize($table_name) ?></span>
                    <div class="tb-meta"><?= number_format($td['row_count']) ?> rows · <?= $td['size_mb'] ?> MB</div>
                </div>
                <div class="d-flex gap-1">
                    <button onclick="editTable('<?= sanitize($table_name) ?>')" class="btn btn-ghost btn-sm" style="padding:2px 5px;font-size:10px" title="Edit Structure">✏️</button>
                    <button onclick="confirmDeleteTable('<?= sanitize($table_name) ?>')" class="btn btn-ghost btn-sm" style="padding:2px 5px;font-size:10px;color:var(--color-danger)" title="Drop Table">🗑️</button>
                </div>
            </div>

            <div class="tb-cols">
                <?php foreach ($td['columns'] as $col): ?>
                <div class="tb-col">
                    <div class="d-flex align-center gap-2">
                        <?php if ($col['Key'] === 'PRI'): ?>
                            <div class="col-handle" data-table="<?= sanitize($table_name) ?>" data-column="<?= sanitize($col['Field']) ?>" title="Drag to create Relationship"></div>
                        <?php endif; ?>
                        <div>
                            <span class="col-name"><?= sanitize($col['Field']) ?></span>
                            <div class="col-type"><?= sanitize($col['Type']) ?></div>
                        </div>
                    </div>
                    <div class="col-flags">
                        <?php if ($col['Key'] === 'PRI'): ?><span class="col-flag flag-pk">PK</span><?php endif; ?>
                        <?php if ($col['Key'] === 'MUL'): ?><span class="col-flag flag-fk">FK</span><?php endif; ?>
                        <?php if ($col['Key'] === 'UNI'): ?><span class="col-flag flag-uq">UQ</span><?php endif; ?>
                        <?php if ($col['Null'] === 'NO' && $col['Key'] !== 'PRI'): ?><span class="col-flag flag-nn">NN</span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- SVG Relationship Lines / Linee di Relazione SVG -->
        <svg id="relSVG" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:5;overflow:visible">
            <defs>
                <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
                    <polygon points="0 0,10 3.5,0 7"/>
                </marker>
            </defs>
            <?php foreach ($schema['relationships'] as $rel): ?>
            <line class="rel-line"
                  marker-end="url(#arrowhead)"
                  data-from="<?= sanitize($rel['from_table']) ?>"
                  data-to="<?= sanitize($rel['to_table']) ?>">
                <title><?= sanitize($rel['from_table']) ?>.<?= sanitize($rel['from_column']) ?> → <?= sanitize($rel['to_table']) ?>.<?= sanitize($rel['to_column']) ?></title>
            </line>
            <?php endforeach; ?>
        </svg>
    </div>
    <div class="schema-hint">Drag tables to rearrange · Click PK dots to link · Scroll to zoom</div>
</div>

<!-- Schema Action Modals / Finestre per azioni Schema -->
<div class="modal-overlay" id="schemaModal">
    <div class="modal-card">
        <div class="card-header d-flex justify-between align-center">
            <h3 class="card-title" id="modalTitle">Schema Action</h3>
            <button onclick="closeModal()" class="btn btn-ghost">✕</button>
        </div>
        <div style="padding:1.5rem;" id="modalBody">
            <!-- Dynamic Content / Contenuto dinamico -->
        </div>
        <div class="card-header d-flex justify-end gap-2" style="border-top:1px solid var(--border-subtle);background:rgba(255,255,255,.02)">
            <button onclick="closeModal()" class="btn btn-ghost">Cancel</button>
            <button id="modalConfirmBtn" class="btn btn-primary">Confirm</button>
        </div>
    </div>
</div>

<?php if (empty($schema['tables'])): ?>
<div class="empty-state mt-4">
    <span class="empty-state-icon">🔗</span>
    <div class="empty-state-title">No tables found</div>
    <p class="empty-state-text"><a href="tables.php" style="color:var(--accent-primary)">Create your first table →</a></p>
</div>
<?php endif; ?>

<?php
$schema_json    = json_encode($schema);
$position_json  = json_encode($table_positions);
$selected_db_js = json_encode($selected_db);
?>
<script>
(function(){
    'use strict';

    const schemaData   = <?= json_encode($schema) ?>;
    const initPositions = <?= json_encode($table_positions) ?>;
    const dbName       = <?= json_encode($selected_db) ?>;
    let   zoomLevel    = 1;
    let   relsVisible  = true;
    let   dragging     = null;
    let   dragOffX     = 0;
    let   dragOffY     = 0;
    let   selectedPK   = null;

    /* ── Core Diagram & Dragging ── */
    function initDrag(){
        document.querySelectorAll('.tb').forEach(el => {
            el.addEventListener('mousedown', e => {
                if(e.target.closest('.tb-cols') || e.target.closest('button') || e.target.closest('.col-handle')) return;
                dragging  = el;
                const rect = el.getBoundingClientRect();
                dragOffX  = e.clientX - rect.left;
                dragOffY  = e.clientY - rect.top;
                el.style.zIndex = 20;
                e.preventDefault();
            });
        });

        document.addEventListener('mousemove', e => {
            if(!dragging) return;
            const cont = document.getElementById('schemaContainer').getBoundingClientRect();
            dragging.style.left = Math.max(0, (e.clientX - cont.left - dragOffX) / zoomLevel) + 'px';
            dragging.style.top  = Math.max(0, (e.clientY - cont.top  - dragOffY) / zoomLevel) + 'px';
            drawLines();
        });

        document.addEventListener('mouseup', () => {
            if(dragging){ dragging.style.zIndex = 10; dragging = null; }
        });
    }

    function drawLines(){
        const cont = document.getElementById('schemaContainer').getBoundingClientRect();
        document.querySelectorAll('.rel-line').forEach(line => {
            const fromEl = document.getElementById('tb-' + line.dataset.from);
            const toEl   = document.getElementById('tb-' + line.dataset.to);
            if(!fromEl || !toEl) return;
            const fr = fromEl.getBoundingClientRect();
            const tr = toEl.getBoundingClientRect();
            line.setAttribute('x1', fr.right  - cont.left);
            line.setAttribute('y1', fr.top + fr.height/2 - cont.top);
            line.setAttribute('x2', tr.left   - cont.left);
            line.setAttribute('y2', tr.top + tr.height/2 - cont.top);
        });
    }

    /* ── Visual Editor Logic ── */
    function initEditor(){
        // PK Linking Handles
        document.querySelectorAll('.col-handle').forEach(dot => {
            dot.addEventListener('click', (e) => {
                e.stopPropagation();
                if(selectedPK === dot) {
                    dot.classList.remove('selected');
                    selectedPK = null;
                    return;
                }
                document.querySelectorAll('.col-handle').forEach(d => d.classList.remove('selected'));
                dot.classList.add('selected');
                selectedPK = dot;
                showHint('Now click on another table to create a Foreign Key link');
            });
        });

        // Click table to link PK
        document.querySelectorAll('.tb').forEach(tableEl => {
            tableEl.addEventListener('click', (e) => {
                if(!selectedPK || e.target.closest('.col-handle') || e.target.closest('button')) return;
                const fromTable = selectedPK.dataset.table;
                const fromCol   = selectedPK.dataset.column;
                const toTable   = tableEl.dataset.table;
                if(fromTable === toTable) return;
                confirmCreateRelationship(fromTable, fromCol, toTable);
            });
        });
    }

    /* ── Modals & Actions ── */
    const modal = document.getElementById('schemaModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalBody = document.getElementById('modalBody');
    const modalConfirmBtn = document.getElementById('modalConfirmBtn');

    window.openModal = function(title, bodyHTML, onConfirm) {
        modalTitle.textContent = title;
        modalBody.innerHTML = bodyHTML;
        modal.classList.add('active');
        modalConfirmBtn.onclick = onConfirm;
    };

    window.closeModal = function() {
        modal.classList.remove('active');
        if(selectedPK) selectedPK.classList.remove('selected');
        selectedPK = null;
    };

    window.openCreateTableModal = function() {
        openModal('Create New Table', `
            <div class="form-group">
                <label class="form-label">Table Name</label>
                <input type="text" id="newTableName" class="form-input" placeholder="e.g. products">
                <small class="form-text">A basic table with 'id' and 'created_at' will be created.</small>
            </div>
        `, async () => {
            const name = document.getElementById('newTableName').value;
            if(!name) return;
            await runSchemaAction('create_table', {name});
        });
    };

    window.confirmDeleteTable = function(table) {
        openModal('Delete Table', `
            <p>Are you sure you want to drop the table <strong>${table}</strong>?</p>
            <p class="text-danger" style="margin-top:1rem">⚠️ This action is irreversible and all data will be lost.</p>
        `, async () => {
            await runSchemaAction('drop_table', {table});
        });
    };

    window.confirmCreateRelationship = function(fromT, fromC, toT) {
        openModal('Create Relationship', `
            <p>Linking <strong>${fromT}.${fromC}</strong> to <strong>${toT}</strong>.</p>
            <p style="margin-top:1rem">This will:</p>
            <ul style="padding-left:1.5rem; font-size:.85rem">
                <li>Add a column <code>${fromT}_id</code> to ${toT}</li>
                <li>Create a Foreign Key constraint</li>
            </ul>
        `, async () => {
            await runSchemaAction('create_fk', {
                from_table: fromT, from_col: fromC,
                to_table: toT, to_col: 'id'
            });
        });
    };

    async function runSchemaAction(action, data) {
        modalConfirmBtn.disabled = true;
        modalConfirmBtn.textContent = 'Processing...';
        const fd = new FormData();
        fd.append('action', action);
        for(let k in data) fd.append(k, data[k]);
        try {
            const res = await fetch('api/schema_edit.php', { method:'POST', body:fd });
            const result = await res.json();
            if(result.success) location.reload();
            else alert('Error: ' + result.error);
        } catch(e) {
            alert('Connection error');
        } finally {
            modalConfirmBtn.disabled = false;
        }
    }

    /**
     * Controls & Utilities / Controlli e Utility
     */
    window.schemaZoom = function(factor){
        zoomLevel = Math.min(3, Math.max(0.2, zoomLevel * factor));
        document.getElementById('schemaCanvas').style.transform = `scale(${zoomLevel})`;
        drawLines();
    };

    window.schemaReset = function(){
        Object.entries(initPositions).forEach(([name, pos]) => {
            const el = document.getElementById('tb-' + name);
            if(el){ el.style.left = pos.x + 'px'; el.style.top = pos.y + 'px'; }
        });
        zoomLevel = 1;
        document.getElementById('schemaCanvas').style.transform = 'scale(1)';
        drawLines();
    };

    window.schemaToggleRels = function(){
        relsVisible = !relsVisible;
        document.getElementById('relSVG').style.display = relsVisible ? 'block' : 'none';
        document.getElementById('btnToggleRel').textContent = relsVisible ? '🔗 Hide Relationships' : '🔗 Show Relationships';
    };

    window.schemaExport = function(){
        const blob = new Blob([JSON.stringify({database: dbName, generated: new Date().toISOString()}, null, 2)], {type:'application/json'});
        const a = Object.assign(document.createElement('a'), {href: URL.createObjectURL(blob), download: `${dbName}_schema.json`});
        document.body.appendChild(a); a.click(); a.remove();
    };

    function showHint(text) {
        const hint = document.querySelector('.schema-hint');
        hint.textContent = text;
        hint.style.color = 'var(--accent-primary)';
        setTimeout(() => {
            hint.textContent = 'Drag tables to rearrange · Click PK dots to link · Scroll to zoom';
            hint.style.color = '';
        }, 5000);
    }

    // Scroll to zoom
    document.getElementById('schemaContainer').addEventListener('wheel', e => { 
        e.preventDefault(); 
        schemaZoom(e.deltaY < 0 ? 1.1 : 0.9); 
    }, {passive: false});

    document.addEventListener('DOMContentLoaded', () => { 
        initDrag(); 
        initEditor(); 
        setTimeout(drawLines, 200); 
    });

    window.addEventListener('resize', drawLines);
    window.editTable = function(table) { window.location.href = `tables.php?table=${table}&action=edit`; };
})();
</script>

<?php include 'includes/footer.php'; ?>