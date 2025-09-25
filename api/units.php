<?php
/**
 * Units API - Academic Units/Departments
 * ======================================
 *
 * Provides information about university units (departments/colleges/offices):
 * - GET: Retrieve units by type or all units
 * - Query parameter 'type': 'office', 'college', or omit for all
 *
 * Units are used for organizing events, users, and other resources.
 * Types: 'office' (administrative), 'college' (academic departments)
 */

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/session.php';
require_once '../includes/database.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $type = isset($_GET['type']) ? trim($_GET['type']) : null; // 'office' | 'college' | null

    if ($type && !in_array($type, ['office', 'college'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
        exit();
    }

    if ($type) {
        $stmt = $db->prepare('SELECT id, name, code, type FROM units WHERE type = :type ORDER BY name');
        $stmt->execute([':type' => $type]);
    } else {
        $stmt = $db->query('SELECT id, name, code, type FROM units ORDER BY type, name');
    }

    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'units' => $units]);
} catch (Throwable $e) {
    error_log('Units API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>
