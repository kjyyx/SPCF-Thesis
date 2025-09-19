-- Update password hashes to match expected passwords
-- Admin (ADM001): password = 'admin123'
UPDATE administrators SET password = '$2y$10$KHzeZ4x47Os17QC99z1q5et41w1.StlyYQ2fQgQ8j5oYAHd4M106q' WHERE id = 'ADM001';

-- Employee (EMP001): password = 'admin123'
UPDATE employees SET password = '$2y$10$QchKOSOLB4VbmZVJSIma5..oSAhSrpB7MU8bIjdTcm7uo1xj.XRIi' WHERE id = 'EMP001';

-- Student (STU001): password = 'student123'
UPDATE students SET password = '$2y$10$kTyBM/Y5qYsfG//XtGVQw.N8BJQ6pcmkYCh0qsTxziLmkgTQgrQiS' WHERE id = 'STU001';