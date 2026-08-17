<?php
/**
 * Automation Controller
 * Demonstrates the automation system in action
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/automation.php';

// Check if user is authenticated
if (!isAuthenticated() || !validateSessionToken()) {
    header('Location: login.php');
    exit;
}

// Get the automation manager from session
$automationManager = $_SESSION['automation_manager'] ?? null;

if (!$automationManager) {
    showMessage('Automation system not available', 'error');
    header('Location: index.php');
    exit;
}

// Handle automation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'run_automation') {
        $automationId = (int)($_POST['automation_id'] ?? 0);
        if ($automationManager->runAutomation($automationId)) {
            showMessage('Automation executed successfully', 'success');
        } else {
            showMessage('Failed to execute automation', 'error');
        }
        header('Location: automation.php');
        exit;
    }
    
    if ($action === 'toggle_automation') {
        $automationId = (int)($_POST['automation_id'] ?? 0);
        if ($automationManager->isAutomationEnabled($automationId)) {
            $automationManager->disableAutomation($automationId);
            showMessage('Automation disabled', 'success');
        } else {
            $automationManager->enableAutomation($automationId);
            showMessage('Automation enabled', 'success');
        }
        header('Location: automation.php');
        exit;
    }
}

// Get all automations
$automations = $automationManager->getAllAutomations();

// Include header
$current_page = 'automation';
$page_title = __('automation', 'Automation');
$page_heading = __('automation', 'Automation');
$page_description = __('manage_automations', 'Manage your automations');

include 'includes/header.php';
?>