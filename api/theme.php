<?php
require_once dirname(__DIR__) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method Not Allowed']));
}

$data = json_decode(file_get_contents('php://input'), true);
$theme = $data['theme'] ?? '';

if (!in_array($theme, ['light', 'dark'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid theme']));
}

// Store in session
$_SESSION['theme_preference'] = $theme;

// If authenticated, update the DB
if (isAuthenticated() && validateSessionToken()) {
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $pdo = new PDO("mysql:host=" . AUTH_DB_HOST . ";dbname=" . AUTH_DB_NAME . ";charset=utf8mb4", AUTH_DB_USER, AUTH_DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Just attempt the update. If the column doesn't exist yet, it will fail silently in the API,
            // but we'll create the column in login.php
            $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE id = ?");
            $stmt->execute([$theme, $uid]);
        }
    } catch (Exception $e) {
        // Silently fail if DB error (e.g., column doesn't exist yet)
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'theme' => $theme]);
