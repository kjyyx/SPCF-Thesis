<?php
/**
 * System Utilities
 * ================
 * Global helper functions for the Sign-um System.
 * Included globally to provide standardized responses, logging, and data formatting.
 */

/**
 * Standardizes API JSON responses and halts execution.
 * This approach mirrors the global helper functions found in frameworks like Laravel, 
 * keeping your controllers clean and your frontend expectations consistent.
 * * @param bool $success Whether the operation was successful
 * @param mixed $payload String message or Array of additional data
 * @param int $statusCode HTTP standard status code (200, 400, 401, 403, 404, 500)
 */
function sendJsonResponse($success, $payload = '', $statusCode = 200) {
    http_response_code($statusCode);
    
    // If the payload is just a string, wrap it in a 'message' key
    if (is_string($payload)) {
        $response = [
            'success' => $success,
            'message' => $payload
        ];
    } else {
        // If it's an array, merge it with the success flag
        $response = array_merge(['success' => $success], $payload);
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Centralized Audit Logging
 * * @param PDO $pdo Active database connection
 * @param string $action Short action code (e.g., 'LOGIN', 'EVENT_CREATE')
 * @param string $category Grouping category (e.g., 'Authentication', 'Events')
 * @param string $details Human-readable description of what happened
 * @param int|null $targetId The ID of the affected record (if applicable)
 * @param string|null $targetType The table or entity type affected
 * @param string $severity 'INFO', 'WARNING', 'CRITICAL'
 */
function addAuditLog($pdo, $action, $category, $details, $targetId = null, $targetType = null, $severity = 'INFO') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (user_id, user_role, user_name, action, category, details, target_id, target_type, severity, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_role'] ?? 'system',
            $_SESSION['first_name'] ?? 'Unknown User',
            $action, 
            $category, 
            $details, 
            $targetId, 
            $targetType, 
            $severity,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Fail silently so a logging error doesn't break the main application flow
        error_log("Failed to add audit log: " . $e->getMessage());
    }
}

/**
 * Sanitizes basic text input to prevent XSS before storing in the database.
 * * @param string $data Raw input string
 * @return string Sanitized string
 */
function sanitizeInput($data) {
    if (empty($data)) return '';
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Generates a secure random verification code.
 * Useful for 2FA, password resets, or document tracking numbers.
 * * @param int $length Defaults to 6 digits
 * @return string
 */
function generateVerificationCode($length = 6) {
    $min = pow(10, $length - 1);
    $max = pow(10, $length) - 1;
    return (string) random_int($min, $max);
}

// ------------------------------------------------------------------
// Helper Functions
// ------------------------------------------------------------------

function getDepartmentFullName($dept) {
    $map = [
        'College of Arts, Social Sciences and Education' => 'College of Arts, Social Sciences, and Education',
        'College of Business' => 'College of Business',
        'College of Computing and Information Sciences' => 'College of Computing and Information Sciences',
        'College of Criminology' => 'College of Criminology',
        'College of Engineering' => 'College of Engineering',
        'College of Hospitality and Tourism Management' => 'College of Hospitality and Tourism Management',
        'College of Nursing' => 'College of Nursing',
        'SPCF Miranda' => 'SPCF Miranda',
        'Supreme Student Council (SSC)' => 'Supreme Student Council (SSC)',
    ];
    return $map[$dept] ?? $dept;
}

/**
 * DRY Helper to fetch signatories instead of repeating SQL blocks 15 times
 */
function fetchSignatory($db, $role, $position, $dept = null) {
    $table = ($role === 'student') ? 'students' : 'employees';
    $sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM $table WHERE position = ?";
    $params = [$position];
    if ($dept) {
        $sql .= " AND department = ?";
        $params[] = $dept;
    }
    $sql .= " LIMIT 1";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $res ? ['id' => $res['id'], 'name' => $res['name']] : ['id' => null, 'name' => $position];
}