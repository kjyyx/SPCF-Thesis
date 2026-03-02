<?php
/**
 * Admin Data API
 * Dedicated endpoint for Admins to view global system data (Materials, Documents) 
 * without Student/Employee role restrictions.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once ROOT_PATH . 'includes/auth.php';
require_once ROOT_PATH . 'includes/session.php';
require_once ROOT_PATH . 'includes/database.php';

// Strict security check: Only Admins can access this file
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $type = $_GET['type'] ?? '';

    // Pagination setup
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $offset = ($page - 1) * $limit;

    if ($type === 'materials') {
        $whereClauses = [];
        $params = [];

        // Status Filter
        if (!empty($_GET['status'])) {
            $status = $_GET['status'];
            if ($status === 'pending') {
                $whereClauses[] = "(m.status = 'pending' OR m.status = 'submitted')";
            } else {
                $whereClauses[] = "m.status = :status";
                $params[':status'] = $status;
            }
        }

        // Search Filter
        if (!empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $whereClauses[] = "(m.title LIKE :search OR s.first_name LIKE :search OR s.last_name LIKE :search)";
            $params[':search'] = $search;
        }

        // Department Filter
        if (!empty($_GET['department'])) {
            $whereClauses[] = "s.department = :dept";
            $params[':dept'] = $_GET['department'];
        }

        $whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Count Query
        $countStmt = $db->prepare("SELECT COUNT(*) FROM materials m LEFT JOIN students s ON m.student_id = s.id $whereSql");
        foreach ($params as $key => $val) $countStmt->bindValue($key, $val);
        $countStmt->execute();
        $totalRows = $countStmt->fetchColumn();
        $totalPages = ceil($totalRows / $limit);

        // Fetch Query
        $query = "SELECT m.*, CONCAT(s.first_name, ' ', s.last_name) as submitted_by, s.department 
                  FROM materials m 
                  LEFT JOIN students s ON m.student_id = s.id 
                  $whereSql
                  ORDER BY m.uploaded_at DESC 
                  LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($query);
        foreach ($params as $key => $val) $stmt->bindValue($key, $val);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'materials' => $materials,
            'totalPages' => $totalPages
        ]);

    } elseif ($type === 'documents') {
        $whereClauses = [];
        $params = [];

        // Status Filter
        if (!empty($_GET['status'])) {
            $status = $_GET['status'];
            if ($status === 'pending') {
                // Pending usually means submitted or in_review or in_progress in documents workflow
                $whereClauses[] = "(d.status = 'submitted' OR d.status = 'in_review' OR d.status = 'pending')";
            } else {
                $whereClauses[] = "d.status = :status";
                $params[':status'] = $status;
            }
        }

        // Search Filter
        if (!empty($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $whereClauses[] = "(d.title LIKE :search OR s.first_name LIKE :search OR s.last_name LIKE :search OR d.id LIKE :search)";
            $params[':search'] = $search;
        }

        // Department Filter
        if (!empty($_GET['department'])) {
            // Check both document's stamped department and student's department
            $whereClauses[] = "(d.department = :dept OR s.department = :dept)";
            $params[':dept'] = $_GET['department'];
        }

        // Doc Type Filter
        if (!empty($_GET['doc_type'])) {
            $whereClauses[] = "d.doc_type = :doctype";
            $params[':doctype'] = $_GET['doc_type'];
        }

        $whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Count Query
        $countStmt = $db->prepare("SELECT COUNT(*) FROM documents d LEFT JOIN students s ON d.student_id = s.id $whereSql");
        foreach ($params as $key => $val) $countStmt->bindValue($key, $val);
        $countStmt->execute();
        $totalRows = $countStmt->fetchColumn();
        $totalPages = ceil($totalRows / $limit);

        // Fetch Query
        $query = "SELECT d.*, CONCAT(s.first_name, ' ', s.last_name) as submitted_by, s.department 
                  FROM documents d 
                  LEFT JOIN students s ON d.student_id = s.id 
                  $whereSql
                  ORDER BY d.uploaded_at DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($query);
        foreach ($params as $key => $val) $stmt->bindValue($key, $val);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'documents' => $documents,
            'totalPages' => $totalPages
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data type requested']);
    }

} catch (PDOException $e) {
    error_log("Admin Data API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}