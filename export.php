<?php
require_once 'config.php';

// Require authentication and validate session
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

$current_page = 'export';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    showMessage('Please select a database first.', 'error');
    redirect('index.php');
}

$db = new Database($selected_db);
$tables = $db->getTables();

/**
 * Ensure export directory exists / Assicura che la directory di esportazione esista
 */
function getExportPath()
{
    $export_dir = __DIR__ . DIRECTORY_SEPARATOR . 'exports';
    if (!is_dir($export_dir)) {
        mkdir($export_dir, 0755, true);
    }
    return $export_dir;
}

function sanitizeFilename($name)
{
    return preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name);
}

function detectCsvDelimiter($file_path)
{
    // Try to detect delimiter by analyzing first few lines
    $delimiters = [',', ';', '\t', '|'];
    $delimiter_counts = [];

    $file = fopen($file_path, 'r');
    if (!$file)
        return ','; // Default to comma

    $sample = fread($file, 1024); // Read first 1KB
    fclose($file);

    foreach ($delimiters as $delimiter) {
        $count = substr_count($sample, $delimiter);
        if ($count > 0) {
            $delimiter_counts[$delimiter] = $count;
        }
    }

    // Return delimiter with highest count
    if (!empty($delimiter_counts)) {
        arsort($delimiter_counts);
        return key($delimiter_counts);
    }

    return ','; // Default to comma
}

function exportTableToCSV(Database $db, string $table): string
{
    $timestamp = date('Y-m-d_H-i-s');
    $filename = sanitizeFilename("{$db->getCurrentDatabase()}_{$table}_{$timestamp}.csv");
    $export_path = getExportPath() . DIRECTORY_SEPARATOR . $filename;

    // Fetch all data
    $stmt = $db->query("SELECT * FROM `{$table}`");
    $rows = $stmt->fetchAll();

    $fp = fopen($export_path, 'w');
    if ($fp === false) {
        throw new Exception('Unable to create export file');
    }

    // Set UTF-8 BOM for Excel compatibility
    fprintf($fp, "\xEF\xBB\xBF");

    // Write header
    if (!empty($rows)) {
        fputcsv($fp, array_keys($rows[0]));
    } else {
        // Fetch columns if empty table
        $structure = $db->getTableStructure($table);
        $columns = array_map(fn($col) => $col['Field'], $structure);
        fputcsv($fp, $columns);
    }

    // Write rows
    foreach ($rows as $row) {
        // Convert nulls to empty strings for CSV
        foreach ($row as $k => $v) {
            if ($v === null)
                $row[$k] = '';
        }
        fputcsv($fp, array_values($row));
    }

    fclose($fp);
    return $filename;
}

function exportTableToJSON(Database $db, string $table): string
{
    $timestamp = date('Y-m-d_H-i-s');
    $filename = sanitizeFilename("{$db->getCurrentDatabase()}_{$table}_{$timestamp}.json");
    $export_path = getExportPath() . DIRECTORY_SEPARATOR . $filename;

    $stmt = $db->query("SELECT * FROM `{$table}`");
    $rows = $stmt->fetchAll();

    $fp = fopen($export_path, 'w');
    if ($fp === false) {
        throw new Exception('Unable to create export file');
    }

    fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fclose($fp);
    
    return $filename;
}

function exportTableToExcel(Database $db, string $table): string
{
    $timestamp = date('Y-m-d_H-i-s');
    $filename = sanitizeFilename("{$db->getCurrentDatabase()}_{$table}_{$timestamp}.xls");
    $export_path = getExportPath() . DIRECTORY_SEPARATOR . $filename;

    $stmt = $db->query("SELECT * FROM `{$table}`");
    
    $fp = fopen($export_path, 'w');
    if ($fp === false) {
        throw new Exception('Unable to create export file');
    }

    // Write as HTML table for high compatibility with older/newer Excel
    fprintf($fp, "<html><head><meta charset=\"UTF-8\"></head><body><table border=\"1\">\n");
    
    $isFirst = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($isFirst) {
            fprintf($fp, "<tr>");
            foreach (array_keys($row) as $key) {
                fprintf($fp, "<th>" . htmlspecialchars((string)$key) . "</th>");
            }
            fprintf($fp, "</tr>\n");
            $isFirst = false;
        }
        fprintf($fp, "<tr>");
        foreach ($row as $val) {
            fprintf($fp, "<td>" . htmlspecialchars((string)($val ?? '')) . "</td>");
        }
        fprintf($fp, "</tr>\n");
    }
    
    fprintf($fp, "</table></body></html>");
    fclose($fp);
    
    return $filename;
}

function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function getExportFiles()
{
    $export_path = getExportPath();
    $files = [];
    if (is_dir($export_path)) {
        foreach (scandir($export_path) as $file) {
            if ($file === '.' || $file === '..')
                continue;
            $full = $export_path . DIRECTORY_SEPARATOR . $file;
            if (is_file($full)) {
                $files[] = [
                    'name' => $file,
                    'size' => filesize($full),
                    'date' => filemtime($full)
                ];
            }
        }
    }
    usort($files, fn($a, $b) => $b['date'] <=> $a['date']);
    return $files;
}

/**
 * Handle export request / Gestione richiesta esportazione
 */
$export_action = $_POST['action'] ?? '';
if ($export_action === 'export_csv' || $export_action === 'export_json' || $export_action === 'export_excel') {
    $table = sanitize($_POST['table'] ?? '');
    if (!$table) {
        showMessage('Please select a table to export.', 'error');
    } else {
        try {
            if ($export_action === 'export_csv') {
                $filename = exportTableToCSV($db, $table);
            } elseif ($export_action === 'export_json') {
                $filename = exportTableToJSON($db, $table);
            } else {
                $filename = exportTableToExcel($db, $table);
            }
            showMessage("Export created: $filename", 'success');
            redirect('export.php');
        } catch (Exception $e) {
            showMessage('Export failed: ' . $e->getMessage(), 'error');
        }
    }
}

/**
 * Handle import CSV / Gestione importazione CSV
 */
if (($_POST['action'] ?? '') === 'import_csv' && isset($_FILES['csv_file'])) {
    $table = sanitize($_POST['table'] ?? '');
    $file = $_FILES['csv_file'];

    if (!$table) {
        showMessage('Please select a target table.', 'error');
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        showMessage('Upload failed. Please try again.', 'error');
    } else {
        // Validate file type
        $file_info = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $file_info->file($file['tmp_name']);

        if ($mime_type !== 'text/csv' && $mime_type !== 'application/vnd.ms-excel') {
            showMessage('Invalid file type. Please upload a CSV file.', 'error');
        } else {
            try {
                $tmp = $file['tmp_name'];
                $delimiter = detectCsvDelimiter($tmp);

                $fp = fopen($tmp, 'r');
                if ($fp === false)
                    throw new Exception('Failed to open uploaded file');

                // Read header with detected delimiter
                $columns = fgetcsv($fp, 0, $delimiter);
                if (!$columns)
                    throw new Exception('CSV header not found');

                // Clean column names
                $columns = array_map('trim', $columns);
                $columns = array_map(function ($col) {
                    // Remove BOM if present
                    return trim($col, "\xEF\xBB\xBF");
                }, $columns);

                // Check if table columns match CSV columns
                $table_structure = $db->getTableStructure($table);
                $table_columns = array_map(fn($col) => $col['Field'], $table_structure);

                $missing_columns = array_diff($columns, $table_columns);
                if (!empty($missing_columns)) {
                    throw new Exception('CSV contains columns that do not exist in the table: ' . implode(', ', $missing_columns));
                }

                // Prepare insert
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $col_list = '`' . implode('`, `', $columns) . '`';
                $sql = "INSERT INTO `{$table}` ({$col_list}) VALUES ({$placeholders})";

                $db->query('START TRANSACTION');
                $row_count = 0;

                while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
                    // Skip empty rows
                    if (count(array_filter($row)) === 0)
                        continue;

                    // Ensure we have the same number of columns as header
                    if (count($row) !== count($columns)) {
                        throw new Exception("Row {$row_count} has incorrect number of columns");
                    }

                    // Convert empty strings to NULL and trim values
                    $values = array_map(function ($v) {
                        if ($v === '' || $v === null)
                            return null;
                        return trim($v);
                    }, $row);

                    $db->query($sql, $values);
                    $row_count++;
                }

                $db->query('COMMIT');
                fclose($fp);

                showMessage("CSV imported successfully. {$row_count} rows imported.", 'success');
                redirect('export.php');
            } catch (Exception $e) {
                $db->query('ROLLBACK');
                showMessage('Import failed: ' . $e->getMessage(), 'error');
            }
        }
    }
}

/**
 * Handle delete export file / Gestione eliminazione file esportato
 */
if ($_POST['action'] ?? '' === 'delete_export') {
    $export_file = sanitize($_POST['export_file'] ?? '');
    $export_path = getExportPath() . DIRECTORY_SEPARATOR . $export_file;

    if (file_exists($export_path) && unlink($export_path)) {
        showMessage("Export file deleted successfully!", 'success');
    } else {
        showMessage("Error deleting export file!", 'error');
    }
    redirect('export.php');
}

$export_files = getExportFiles();
$page_title = 'Export/Import';
$page_heading = 'Export/Import';
include 'includes/header.php';
?>


<div class="grid grid-2">
    <!-- Export CSV / Esporta CSV -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">📤 Export Data</div>
                <div class="card-subtitle">Download table data in your preferred format</div>
            </div>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="export_csv">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <div class="form-group">
                <label class="form-label">Select Table</label>
                <select name="table" class="form-select" required>
                    <option value="">-- Select Table --</option>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= sanitize($table) ?>">
                            <?= sanitize($table) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" name="action" value="export_csv" class="btn btn-success">📤 Export CSV</button>
                <button type="submit" name="action" value="export_json" class="btn btn-info">📄 Export JSON</button>
                <button type="submit" name="action" value="export_excel" class="btn btn-primary" style="background:var(--accent-primary)">📊 Export Excel</button>
            </div>
        </form>
    </div>

    <!-- Import CSV / Importa CSV -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">📥 Import from CSV</div>
                <div class="card-subtitle">Upload CSV files to populate your tables</div>
            </div>
        </div>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="import_csv">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <div class="form-group">
                <label class="form-label">Target Table</label>
                <select name="table" class="form-select" required>
                    <option value="">-- Select Table --</option>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= sanitize($table) ?>">
                            <?= sanitize($table) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">CSV File</label>
                <input type="file" name="csv_file" class="form-input" accept=".csv" required>
                <small class="form-text">First row must contain column headers. Supports UTF-8 encoding.</small>
            </div>
            <button type="submit" class="btn btn-warning"
                onclick="return confirm('Importing data may overwrite existing rows if keys conflict. Continue?')">📥
                Import CSV</button>
        </form>
    </div>
</div>

<!-- Exported Files / File Esportati -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">📄 Exported Files</div>
            <div class="card-subtitle">Manage your generated CSV exports</div>
        </div>
    </div>
    <?php if (!empty($export_files)): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($export_files as $file): ?>
                        <tr>
                            <td>
                                <?= sanitize($file['name']) ?>
                            </td>
                            <td>
                                <?= formatFileSize($file['size']) ?>
                            </td>
                            <td>
                                <?= date('Y-m-d H:i:s', $file['date']) ?>
                            </td>
                            <td>
                                <a href="exports/<?= urlencode($file['name']) ?>" class="btn btn-sm"> 📥 Download</a>
                                <form method="POST" style="display:inline"
                                    onsubmit="return confirm('Delete exported file <?= sanitize($file['name']) ?>?')">
                                    <input type="hidden" name="action" value="delete_export">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                                    <input type="hidden" name="export_file" value="<?= sanitize($file['name']) ?>">
                                    <button class="btn btn-danger btn-sm">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No exported files yet.</div>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const forms = document.querySelectorAll('form');
        forms.forEach(f => f.addEventListener('submit', function () {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.textContent = '⏳ Processing...'; }
        }));
    })
</script>
<?php include 'includes/footer.php'; ?>