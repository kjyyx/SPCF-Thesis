<?php
// debug-documents.php: Seed three mock documents tied to the logged-in employee for testing
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';

requireAuth();
requireRole(['employee']);

$auth = new Auth();
$currentUser = $auth->getUser($_SESSION['user_id'], $_SESSION['user_role']);
if (!$currentUser) {
    logoutUser();
    header('Location: views/user-login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

function ensureStudent($db, $id, $first, $last, $dept) {
    $q = $db->prepare("SELECT id FROM students WHERE id = ?");
    $q->execute([$id]);
    if (!$q->fetch()) {
        $stmt = $db->prepare("INSERT INTO students (id, first_name, last_name, email, password, department, position) VALUES (?,?,?,?,?,'',?)");
        $stmt->execute([$id, $first, $last, strtolower($first).'.'.strtolower($last).'@student.university.edu', password_hash('password', PASSWORD_BCRYPT), $dept, 'Student']);
    }
}

function createDocument($db, $studentId, $type, $title, $desc, $status, $steps) {
    $stmt = $db->prepare("INSERT INTO documents (student_id, doc_type, title, description, status, current_step, uploaded_at) VALUES (?,?,?,?,?,1,NOW())");
    $stmt->execute([$studentId, $type, $title, $desc, $status]);
    $docId = $db->lastInsertId();

    $order = 1;
    foreach ($steps as $s) {
        $st = $db->prepare("INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, status, acted_at, note) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$docId, $order++, $s['name'], $s['assignee_id'], $s['status'], $s['acted_at'], $s['note']]);
    }
    return $docId;
}

try {
    // Ensure demo students
    ensureStudent($db, 'STU101', 'Juan', 'Dela Cruz', 'College of Engineering');
    ensureStudent($db, 'STU102', 'Ana', 'Reyes', 'College of Computing and Information Sciences');
    ensureStudent($db, 'STU103', 'Mark', 'Cruz', 'College of Business');

    // 1) Urgent (submitted) with pending step assigned to current employee
    createDocument(
        $db,
        'STU101',
        'proposal',
        'Research Proposal: IoT Campus Network',
        'Proposal to deploy IoT sensors across campus for energy efficiency.',
        'submitted',
        [
            ['name' => 'Initial Review', 'assignee_id' => $currentUser['id'], 'status' => 'pending', 'acted_at' => null, 'note' => null],
            ['name' => 'Dean Approval', 'assignee_id' => $currentUser['id'], 'status' => 'pending', 'acted_at' => null, 'note' => null]
        ]
    );

    // 2) High (in_review) with previous completed then pending assigned to current employee
    createDocument(
        $db,
        'STU102',
        'saf',
        'Student Activity Form: TechWeek 2025',
        'Annual technology week with workshops and hackathons.',
        'in_review',
        [
            ['name' => 'Organization Adviser', 'assignee_id' => $currentUser['id'], 'status' => 'completed', 'acted_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'note' => 'Looks good.'],
            ['name' => 'Student Affairs Office', 'assignee_id' => $currentUser['id'], 'status' => 'pending', 'acted_at' => null, 'note' => null]
        ]
    );

    // 3) Normal (approved) fully completed
    createDocument(
        $db,
        'STU103',
        'facility',
        'Facility Request: Auditorium Booking',
        'Request to use the main auditorium for orientation.',
        'approved',
        [
            ['name' => 'Facilities Review', 'assignee_id' => $currentUser['id'], 'status' => 'completed', 'acted_at' => date('Y-m-d H:i:s', strtotime('-4 days')), 'note' => 'Available'],
            ['name' => 'Dean Approval', 'assignee_id' => $currentUser['id'], 'status' => 'completed', 'acted_at' => date('Y-m-d H:i:s', strtotime('-3 days')), 'note' => 'Approved']
        ]
    );

    echo "<html><body style='font-family: Arial; padding:20px'>";
    echo "<h3>Mock documents created for employee {$currentUser['first_name']} {$currentUser['last_name']} ({$currentUser['id']})</h3>";
    echo "<ul>";
    echo "<li>1 Urgent (submitted)</li>";
    echo "<li>1 High (in_review)</li>";
    echo "<li>1 Normal (approved)</li>";
    echo "</ul>";
    echo "<p>Go to <a href='views/notifications.php' target='_blank'>views/notifications.php</a> to test. You can also append <code>?mock=1</code> to use front-end mock data.</p>";
    echo "</body></html>";
} catch (Exception $e) {
    http_response_code(500);
    echo "<pre>Failed to create mock data: " . htmlspecialchars($e->getMessage()) . "</pre>";
}
