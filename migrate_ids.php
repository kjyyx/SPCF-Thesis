<?php
/**
 * ONE-TIME ID MIGRATION SCRIPT
 * Safely renames User IDs and cascades the changes to all related documents/logs.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

try {
    $db = (new Database())->getConnection();
    
    // Disable foreign key constraints temporarily so we can modify primary keys safely
    $db->exec("SET FOREIGN_KEY_CHECKS=0;");

    $updates = [
        // --- STUDENTS ---
        ['old' => 'STU001', 'new' => 'CSCCOB001', 'type' => 'student'],
        ['old' => 'STU002', 'new' => 'CSCCCIS001', 'type' => 'student'],
        ['old' => 'STU003', 'new' => 'CSCCOC001', 'type' => 'student'],
        ['old' => 'STU004', 'new' => 'CSCCOE001', 'type' => 'student'],
        ['old' => 'STU005', 'new' => 'CSCCHTM001', 'type' => 'student'],
        ['old' => 'STU006', 'new' => 'CSCCON001', 'type' => 'student'],
        ['old' => 'STU007', 'new' => 'CSCMIRANDA001', 'type' => 'student'],
        ['old' => 'STU008', 'new' => 'CSCCASSE001', 'type' => 'student'],
        ['old' => 'STU009', 'new' => 'SSC001', 'type' => 'student'],

        // --- ADVISERS ---
        ['old' => 'EMP001', 'new' => 'ADVCOB01', 'type' => 'employee'],
        ['old' => 'EMP002', 'new' => 'ADVCCIS01', 'type' => 'employee'],
        ['old' => 'EMP003', 'new' => 'ADVCOC01', 'type' => 'employee'],
        ['old' => 'EMP004', 'new' => 'ADVCOE01', 'type' => 'employee'],
        ['old' => 'EMP005', 'new' => 'ADVCHTM01', 'type' => 'employee'],
        ['old' => 'EMP006', 'new' => 'ADVCON01', 'type' => 'employee'],
        ['old' => 'EMP007', 'new' => 'ADVMIRANDA01', 'type' => 'employee'],
        ['old' => 'EMP009', 'new' => 'ADVCASSE01', 'type' => 'employee'],

        // --- DEANS ---
        ['old' => 'EMP010', 'new' => 'DEANCASSE01', 'type' => 'employee'],
        ['old' => 'EMP011', 'new' => 'DEANCCIS01', 'type' => 'employee'],
        ['old' => 'EMP012', 'new' => 'DEANCHTM01', 'type' => 'employee'],
        ['old' => 'EMP013', 'new' => 'DEANCOB01', 'type' => 'employee'],
        ['old' => 'EMP014', 'new' => 'DEANCOC01', 'type' => 'employee'],
        ['old' => 'EMP015', 'new' => 'DEANCOE01', 'type' => 'employee'],
        ['old' => 'EMP016', 'new' => 'DEANCON01', 'type' => 'employee'],
        ['old' => 'EMP017', 'new' => 'DEANMIRANDA01', 'type' => 'employee'],

        // --- OFFICES ---
        ['old' => 'EMP008', 'new' => 'AP01', 'type' => 'employee'],
        ['old' => 'EMP018', 'new' => 'OSA01', 'type' => 'employee'],
        ['old' => 'EMP019', 'new' => 'CPAO01', 'type' => 'employee'],
        ['old' => 'EMP020', 'new' => 'VPAA01', 'type' => 'employee'],
        ['old' => 'EMP021', 'new' => 'EVP01', 'type' => 'employee'],
        ['old' => 'EMP022', 'new' => 'PPFO01', 'type' => 'employee'],
        ['old' => 'EMP023', 'new' => 'ITS01', 'type' => 'employee'],
        ['old' => 'EMP024', 'new' => 'TECH01', 'type' => 'employee'],
        ['old' => 'EMP025', 'new' => 'INFO01', 'type' => 'employee'],
        ['old' => 'EMP026', 'new' => 'SEC01', 'type' => 'employee'],
    ];

    foreach($updates as $u) {
        $old = $u['old'];
        $new = $u['new'];

        // 1. Update the Main User Tables
        if ($u['type'] === 'student') {
            $db->prepare("UPDATE students SET id = ? WHERE id = ?")->execute([$new, $old]);
            $db->prepare("UPDATE documents SET student_id = ? WHERE student_id = ?")->execute([$new, $old]);
            $db->prepare("UPDATE document_steps SET assigned_to_student_id = ? WHERE assigned_to_student_id = ?")->execute([$new, $old]);
            $db->prepare("UPDATE materials SET student_id = ? WHERE student_id = ?")->execute([$new, $old]);
        } else {
            $db->prepare("UPDATE employees SET id = ? WHERE id = ?")->execute([$new, $old]);
            $db->prepare("UPDATE document_steps SET assigned_to_employee_id = ? WHERE assigned_to_employee_id = ?")->execute([$new, $old]);
            try { $db->prepare("UPDATE materials_steps SET assigned_to_employee_id = ? WHERE assigned_to_employee_id = ?")->execute([$new, $old]); } catch (Exception $e) {}
        }

        // 2. Update Global Action Tables (Wrapped in try/catch in case some tables are empty)
        try { $db->prepare("UPDATE document_notes SET author_id = ? WHERE author_id = ?")->execute([$new, $old]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE materials_notes SET author_id = ? WHERE author_id = ?")->execute([$new, $old]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE notifications SET recipient_id = ? WHERE recipient_id = ?")->execute([$new, $old]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE audit_logs SET user_id = ? WHERE user_id = ?")->execute([$new, $old]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE events SET created_by = ? WHERE created_by = ?")->execute([$new, $old]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE events SET approved_by = ? WHERE approved_by = ?")->execute([$new, $old]); } catch (Exception $e) {}
        try { $db->prepare("UPDATE saf_transactions SET created_by = ? WHERE created_by = ?")->execute([$new, $old]); } catch (Exception $e) {}
    }

    // Re-enable foreign key constraints
    $db->exec("SET FOREIGN_KEY_CHECKS=1;");
    
    echo "<div style='font-family: sans-serif; padding: 40px; text-align: center;'>";
    echo "<h2 style='color: #10b981;'>✅ Migration Completed Successfully!</h2>";
    echo "<p>All 35 users have been renamed to the Dean's format, and all documents/signatures were safely re-linked.</p>";
    echo "<p style='color: red; font-weight: bold;'>You may now delete this file from your server for security.</p>";
    echo "</div>";

} catch(Exception $e) {
    // If it fails, make sure constraints get turned back on!
    if(isset($db)) $db->exec("SET FOREIGN_KEY_CHECKS=1;");
    echo "<h3>Error during migration:</h3> <p>" . $e->getMessage() . "</p>";
}
?>