<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isAuthenticated() || !validateSessionToken()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$selected_db) {
    echo json_encode(['success' => false, 'error' => 'No database selected']);
    exit;
}

$db = new Database($selected_db);

try {
    switch ($action) {
        case 'drop_table':
            $table = $_POST['table'] ?? '';
            if (!$table) throw new Exception("Table name required");
            /**
             * Drop table / Elimina tabella
             */
            $db->query("DROP TABLE `" . str_replace('`','``',$table) . "`");
            echo json_encode(['success' => true]);
            break;

        case 'create_table':
            $name = $_POST['name'] ?? '';
            if (!$name) throw new Exception("Table name required");
            /**
             * Simple table with an ID / Tabella semplice con un ID
             */
            $db->query("CREATE TABLE `" . str_replace('`','``',$name) . "` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            echo json_encode(['success' => true]);
            break;

        case 'create_fk':
            $from_table = $_POST['from_table'] ?? '';
            $from_col   = $_POST['from_col'] ?? '';
            $to_table   = $_POST['to_table'] ?? '';
            $to_col     = $_POST['to_col'] ?? '';
            
            if (!$from_table || !$from_col || !$to_table || !$to_col) {
                throw new Exception("Incomplete relationship data");
            }

            /**
             * Simple logic: add a column to 'to_table' and link it / Logica semplice: aggiungi colonna a 'to_table' e collegala
             * Check if column exists in to_table, if not create it / Controlla se la colonna esiste, altrimenti creala
             */
            $new_col = $from_table . "_id";
            
            /**
             * Add column / Aggiungi colonna
             */
            $db->query("ALTER TABLE `$to_table` ADD COLUMN `$new_col` INT");
            
            /**
             * Add Constraint / Aggiungi vincolo (Foreign Key)
             */
            $fk_name = "fk_" . $from_table . "_" . $to_table . "_" . time();
            $db->query("ALTER TABLE `$to_table` ADD CONSTRAINT `$fk_name` 
                       FOREIGN KEY (`$new_col`) REFERENCES `$from_table`(`$from_col`)
                       ON DELETE CASCADE");

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
