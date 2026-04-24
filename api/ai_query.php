<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isAuthenticated() || !validateSessionToken()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$prompt = $_POST['prompt'] ?? '';
$selected_db = $_SESSION['selected_db'] ?? '';

if (!$prompt || !$selected_db) {
    echo json_encode(['success' => false, 'error' => 'Missing prompt or database']);
    exit;
}

if (!defined('OPENROUTER_API_KEY') || empty(OPENROUTER_API_KEY)) {
    echo json_encode(['success' => false, 'error' => 'API Key not configured. Please set AI_API_KEY in your environment or config.php']);
    exit;
}

$db = new Database($selected_db);

/**
 * Get schema for context / Ottieni lo schema per il contesto AI
 */
$schema_context = "";
try {
    $tables = $db->getTables();
    foreach ($tables as $table) {
        $structure = $db->getTableStructure($table);
        $cols = [];
        foreach ($structure as $col) {
            $cols[] = $col['Field'] . " (" . $col['Type'] . ")";
        }
        $schema_context .= "Table `$table`: " . implode(", ", $cols) . "\n";
    }
} catch (Exception $e) {
    $schema_context = "Could not fetch schema: " . $e->getMessage();
}

$system_prompt = "You are a senior MySQL expert. 
If the user asks to generate a query, return ONLY the SQL code.
If the user asks to explain the schema, optimize a table, or has a general database question, provide a clear and concise explanation in Markdown format.
If you provide SQL within an explanation, wrap it in ```sql ... ``` blocks.

CURRENT DATABASE: $selected_db
SCHEMA:
$schema_context";

$payload = [
    'model' => OPENROUTER_MODEL,
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.3
];

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENROUTER_API_KEY,
    'HTTP-Referer: https://github.com/GestioneDb',
    'X-Title: GestioneDb Premium'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['success' => false, 'error' => 'AI API Error: ' . ($response ?: 'Status ' . $http_code)]);
    exit;
}

$result = json_decode($response, true);
$content = $result['choices'][0]['message']['content'] ?? '';

// Determine if the response is pure SQL or a markdown explanation
$is_pure_sql = !preg_match('/[a-zA-Z]{4,}/', $content) || (strpos($content, 'SELECT') !== false && strpos($content, '```') === false);
$clean_sql = preg_replace('/^```sql\s*|\s*```$/i', '', trim($content));

echo json_encode([
    'success' => true, 
    'content' => $content,
    'is_sql' => $is_pure_sql,
    'sql' => $clean_sql
]);
