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

    // Pagination setup - default to 50, strictly ensure positive integers
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50;
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

        // Count Query (For Pagination Math)
        $countStmt = $db->prepare("SELECT COUNT(*) FROM materials m LEFT JOIN students s ON m.student_id = s.id $whereSql");
        foreach ($params as $key => $val) $countStmt->bindValue($key, $val);
        $countStmt->execute();
        $totalRows = $countStmt->fetchColumn();
        $totalPages = ceil($totalRows / $limit) ?: 1;

        // Fetch Query (With Reviewer details for Modal)
        $query = "SELECT m.*, CONCAT(s.first_name, ' ', s.last_name) as submitted_by, s.department,
                         (SELECT CONCAT(e.first_name, ' ', e.last_name) FROM materials_steps ms LEFT JOIN employees e ON ms.assigned_to_employee_id = e.id WHERE ms.material_id = m.id AND ms.status = 'completed' ORDER BY ms.step_order DESC LIMIT 1) as approved_by_name,
                         m.approved_at,
                         (SELECT CONCAT(e.first_name, ' ', e.last_name) FROM materials_steps ms LEFT JOIN employees e ON ms.assigned_to_employee_id = e.id WHERE ms.material_id = m.id AND ms.status = 'rejected' LIMIT 1) as rejected_by,
                         m.rejected_at,
                         (SELECT ms.note FROM materials_steps ms WHERE ms.material_id = m.id AND ms.status = 'rejected' LIMIT 1) as rejection_reason
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
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'totalRecords' => $totalRows
        ]);

    } elseif ($type === 'documents') {
        $whereClauses = [];
        $params = [];

        // Status Filter
        if (!empty($_GET['status'])) {
            $status = $_GET['status'];
            if ($status === 'pending') {
                $whereClauses[] = "(d.status = 'submitted' OR d.status = 'in_review' OR d.status = 'pending' OR d.status = 'in_progress')";
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
            $whereClauses[] = "(d.department = :dept OR s.department = :dept)";
            $params[':dept'] = $_GET['department'];
        }

        // Doc Type Filter
        if (!empty($_GET['doc_type'])) {
            $whereClauses[] = "d.doc_type = :doctype";
            $params[':doctype'] = $_GET['doc_type'];
        }

        $whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Count Query (For Pagination Math)
        $countStmt = $db->prepare("SELECT COUNT(*) FROM documents d LEFT JOIN students s ON d.student_id = s.id $whereSql");
        foreach ($params as $key => $val) $countStmt->bindValue($key, $val);
        $countStmt->execute();
        $totalRows = $countStmt->fetchColumn();
        $totalPages = ceil($totalRows / $limit) ?: 1;

        // Fetch Query (With Reviewer details for Modal)
        $query = "SELECT d.*, CONCAT(s.first_name, ' ', s.last_name) as submitted_by, s.department,
                         (SELECT CONCAT(e.first_name, ' ', e.last_name) FROM document_steps ds LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id WHERE ds.document_id = d.id AND ds.status = 'completed' ORDER BY ds.step_order DESC LIMIT 1) as approved_by_name,
                         (SELECT ds.acted_at FROM document_steps ds WHERE ds.document_id = d.id AND ds.status = 'completed' ORDER BY ds.step_order DESC LIMIT 1) as approved_at,
                         (SELECT CONCAT(e.first_name, ' ', e.last_name) FROM document_steps ds LEFT JOIN employees e ON ds.assigned_to_employee_id = e.id WHERE ds.document_id = d.id AND ds.status = 'rejected' LIMIT 1) as rejected_by,
                         (SELECT ds.acted_at FROM document_steps ds WHERE ds.document_id = d.id AND ds.status = 'rejected' LIMIT 1) as rejected_at,
                         (SELECT ds.note FROM document_steps ds WHERE ds.document_id = d.id AND ds.status = 'rejected' LIMIT 1) as rejection_reason
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
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'totalRecords' => $totalRows
        ]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data type requested']);
    }

} catch (PDOException $e) {
    error_log("Admin Data API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}