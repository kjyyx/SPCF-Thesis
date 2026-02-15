-- Create the database
CREATE DATABASE IF NOT EXISTS SPCF_Thesis_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE SPCF_Thesis_db;

-- Administrators table
CREATE TABLE IF NOT EXISTS administrators (
    id VARCHAR(20) PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    office VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employees table
CREATE TABLE IF NOT EXISTS employees (
    id VARCHAR(20) PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    office VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Students table
CREATE TABLE IF NOT EXISTS students (
    id VARCHAR(20) PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    department VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample data with HASHED passwords (idempotent)
INSERT IGNORE INTO administrators (id, first_name, last_name, email, password, office, position) VALUES
('ADM001', 'System', 'Administrator', 'admin@university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'IT Department', 'System Administrator');

INSERT IGNORE INTO employees (id, first_name, last_name, email, password, office, position) VALUES
('EMP001', 'Maria', 'Santos', 'maria.santos@university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administration Office', 'Dean'),
('EMP002', 'Antonio', 'Rodriguez', 'antonio.rodriguez@university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administration Office', 'Executive Vice President');

INSERT IGNORE INTO students (id, first_name, last_name, email, password, department, position) VALUES
('STU001', 'Juan', 'Dela Cruz', 'juan.delacruz@student.university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'College of Engineering', 'Student');

-- Default system settings
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('enable_2fa', '1');

-- =============================================================
-- Enhancements below: password lifecycle, organizational units,
-- events, documents workflow, notifications, audit, etc.
-- Safe to run multiple times.
-- =============================================================

-- Add password lifecycle flags to user tables (ignore if already present)
-- Note: MySQL before 8.0.29 doesn't support IF NOT EXISTS for ADD COLUMN.
-- These ALTERs will fail if columns already exist; comment them out if re-running after first apply.
ALTER TABLE administrators
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

ALTER TABLE documents MODIFY COLUMN doc_type ENUM('proposal','saf','facility','communication','material') NOT NULL;

-- Organizational units (offices/colleges)
CREATE TABLE IF NOT EXISTS units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL,
    type ENUM('office','college') NOT NULL,
    UNIQUE KEY uq_units_name_type (name, type)
);

-- System settings
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Events (calendar)
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    event_time TIME NULL,
    unit_id INT NULL,
    created_by VARCHAR(20) NOT NULL,
    created_by_role ENUM('admin','employee') NOT NULL,
    source_document_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    approved TINYINT(1) DEFAULT 0,
    approved_by VARCHAR(20) NULL,
    approved_at TIMESTAMP NULL,
    CONSTRAINT fk_events_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
);

-- Add venue column to events table
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue VARCHAR(200) NULL AFTER description;

-- Core document record (submitted by students)
CREATE TABLE IF NOT EXISTS documents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    doc_type ENUM('proposal','saf','facility','communication') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','submitted','in_review','approved','rejected','cancelled') NOT NULL DEFAULT 'submitted',
    current_step INT NOT NULL DEFAULT 1,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_documents_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_documents_type_status (doc_type, status)
);

-- Public materials table
CREATE TABLE IF NOT EXISTS materials (
    id VARCHAR(10) PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    file_path VARCHAR(500) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    file_size_kb INT NOT NULL DEFAULT 0,
    downloads INT NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by VARCHAR(20) NULL,
    approved_at DATETIME NULL,
    rejected_by VARCHAR(20) NULL,
    rejected_at DATETIME NULL,
    CONSTRAINT fk_materials_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_materials_approved_by FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_materials_rejected_by FOREIGN KEY (rejected_by) REFERENCES employees(id) ON DELETE SET NULL,
    INDEX idx_materials_status (status)
);

-- Add new columns for project proposals
ALTER TABLE documents ADD COLUMN IF NOT EXISTS venue VARCHAR(200) NULL AFTER description;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS schedule_summary TEXT NULL AFTER venue;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS earliest_start_time DATETIME NULL AFTER schedule_summary;

-- Add data column for storing document data as JSON
ALTER TABLE documents ADD COLUMN IF NOT EXISTS data JSON NULL AFTER earliest_start_time;

-- Add date column for due dates
ALTER TABLE documents ADD COLUMN IF NOT EXISTS date DATE NULL AFTER data;

-- SAF (Student Allocated Funds) tables
CREATE TABLE IF NOT EXISTS saf_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id VARCHAR(100) NOT NULL UNIQUE,
    initial_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    used_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS saf_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id VARCHAR(100) NOT NULL,
    transaction_type ENUM('add','deduct','set') NOT NULL,
    transaction_amount DECIMAL(10,2) NOT NULL,
    transaction_description TEXT,
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(20),
    INDEX idx_dept_date (department_id, transaction_date)
);

-- Per-document workflow steps
CREATE TABLE IF NOT EXISTS document_steps (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    step_order INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    assigned_to_employee_id VARCHAR(20) NULL,
    assigned_to_student_id VARCHAR(20) NULL,
    status ENUM('pending','completed','rejected','skipped') NOT NULL DEFAULT 'pending',
    acted_at DATETIME NULL,
    note TEXT NULL,
    signature_map TEXT NULL,
    creates_event TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_steps_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_steps_employee FOREIGN KEY (assigned_to_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_steps_student FOREIGN KEY (assigned_to_student_id) REFERENCES students(id) ON DELETE SET NULL,
    UNIQUE KEY uq_doc_step (document_id, step_order)
);

-- Materials steps for approval workflow
CREATE TABLE IF NOT EXISTS materials_steps (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    material_id VARCHAR(10) NOT NULL,
    step_order INT NOT NULL,
    assigned_to_employee_id VARCHAR(20) NULL,
    status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    note TEXT NULL,
    CONSTRAINT fk_materials_steps_material FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
    CONSTRAINT fk_materials_steps_employee FOREIGN KEY (assigned_to_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    UNIQUE KEY uq_material_step (material_id, step_order)
);

-- Signatures captured on steps
CREATE TABLE IF NOT EXISTS document_signatures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    step_id BIGINT NOT NULL,
    employee_id VARCHAR(20) NULL,
    student_id VARCHAR(20) NULL,
    status ENUM('pending','signed','rejected') NOT NULL DEFAULT 'pending',
    signed_at DATETIME NULL,
    signature_path VARCHAR(255) NULL,
    CONSTRAINT fk_sig_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_sig_step FOREIGN KEY (step_id) REFERENCES document_steps(id) ON DELETE CASCADE,
    CONSTRAINT fk_sig_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    CONSTRAINT fk_sig_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    UNIQUE KEY uq_doc_step_signer (document_id, step_id, employee_id, student_id)
);

-- Free-form notes (rework/remarks)
CREATE TABLE IF NOT EXISTS document_notes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    author_id VARCHAR(20) NOT NULL,
    author_role ENUM('admin','employee','student') NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notes_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_notes_author (author_role, author_id)
);

-- File attachments for documents
CREATE TABLE IF NOT EXISTS attachments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NULL,
    file_size_kb INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient_id VARCHAR(20) NOT NULL,
    recipient_role ENUM('admin','employee','student') NOT NULL,
    type ENUM('document','system','event') NOT NULL DEFAULT 'document',
    title VARCHAR(200) NOT NULL,
    message TEXT NULL,
    related_document_id BIGINT NULL,
    related_event_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_document FOREIGN KEY (related_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_notif_event FOREIGN KEY (related_event_id) REFERENCES events(id) ON DELETE SET NULL,
    INDEX idx_notif_recipient (recipient_role, recipient_id, is_read)
);

-- Audit logs (admin dashboard)
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id VARCHAR(20) NULL,
    user_role ENUM('admin','employee','student','system') NOT NULL DEFAULT 'system',
    user_name VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    category VARCHAR(100) NOT NULL,
    details TEXT NULL,
    target_id VARCHAR(50) NULL,
    target_type VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    severity ENUM('INFO','WARNING','ERROR') NOT NULL DEFAULT 'INFO',
    INDEX idx_audit_ts (timestamp),
    INDEX idx_audit_user (user_role, user_id),
    INDEX idx_audit_cat (category, action)
);

-- Login attempts tracking for brute force prevention
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(20) NOT NULL,
    attempts INT DEFAULT 0,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    locked_until TIMESTAMP NULL,
    UNIQUE KEY unique_user (user_id)
);

-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(20) NOT NULL,
    role ENUM('admin','employee','student') NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_user (role, user_id),
    INDEX idx_pr_expires (expires_at)
);

-- Optional: store employee signature images
CREATE TABLE IF NOT EXISTS user_signatures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(20) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_user_sign_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY uq_employee_signature (employee_id)
);

-- Seed organizational units (idempotent)
INSERT IGNORE INTO units(name, code, type) VALUES
 ('Administration Office','ADMIN','office'),
 ('Academic Affairs','ACAD','office'),
 ('Student Affairs','STUD','office'),
 ('Finance Office','FIN','office'),
 ('HR Department','HR','office'),
 ('IT Department','IT','office'),
 ('Library','LIB','office'),
 ('Registrar','REG','office'),
 ('College of Engineering','COE','college'),
 ('College of Nursing','CON','college'),
 ('College of Business','COB','college'),
 ('College of Criminology','COC','college'),
 ('College of Computing and Information Sciences','CCIS','college'),
 ('College of Art and Social Sciences and Education','CASSE','college'),
 ('College of Hospitality and Tourism Management','CHTM','college');

-- Add 2FA secret columns for TOTP authentication
ALTER TABLE administrators ADD COLUMN IF NOT EXISTS 2fa_secret VARCHAR(32) DEFAULT NULL;
ALTER TABLE administrators ADD COLUMN IF NOT EXISTS 2fa_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS 2fa_secret VARCHAR(32) DEFAULT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS 2fa_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE students ADD COLUMN IF NOT EXISTS 2fa_secret VARCHAR(32) DEFAULT NULL;
ALTER TABLE students ADD COLUMN IF NOT EXISTS 2fa_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- Database Cleanup and Standardization Updates
-- Update existing units to standardize names (case-insensitive matching)
UPDATE units SET name = 'College of Arts, Social Sciences and Education' WHERE LOWER(name) LIKE '%arts%social%sciences%education%';
UPDATE units SET name = 'College of Business' WHERE LOWER(name) LIKE '%business%';
UPDATE units SET name = 'College of Computing and Information Sciences' WHERE LOWER(name) LIKE '%computing%information%sciences%';
UPDATE units SET name = 'College of Criminology' WHERE LOWER(name) LIKE '%criminology%';
UPDATE units SET name = 'College of Engineering' WHERE LOWER(name) LIKE '%engineering%';
UPDATE units SET name = 'College of Hospitality and Tourism Management' WHERE LOWER(name) LIKE '%hospitality%tourism%management%';
UPDATE units SET name = 'College of Nursing' WHERE LOWER(name) LIKE '%nursing%';
UPDATE units SET name = 'SPCF Miranda' WHERE LOWER(name) LIKE '%miranda%';
UPDATE units SET name = 'Supreme Student Council (SSC)' WHERE LOWER(name) LIKE '%supreme%student%council%';

-- Remove duplicates (if any) by keeping the first occurrence
DELETE u1 FROM units u1
INNER JOIN units u2 
WHERE u1.id > u2.id AND u1.name = u2.name AND u1.type = u2.type;

-- Insert missing colleges (ignore if exists)
INSERT IGNORE INTO units (name, code, type) VALUES
('College of Arts, Social Sciences and Education', 'CASSE', 'college'),
('College of Business', 'COB', 'college'),
('College of Computing and Information Sciences', 'CCIS', 'college'),
('College of Criminology', 'COC', 'college'),
('College of Engineering', 'COE', 'college'),
('College of Hospitality and Tourism Management', 'CHTM', 'college'),
('College of Nursing', 'CON', 'college'),
('SPCF Miranda', 'MIRANDA', 'college'),
('Supreme Student Council (SSC)', 'SSC', 'college');

-- Ensure offices are present (from your implied list, e.g., OIC-OSA, etc.)
INSERT IGNORE INTO units (name, code, type) VALUES
('Office of Student Affairs', 'OSA', 'office'),
('Center for Performing Arts Organization', 'CPAO', 'office'),
('Academic Affairs', 'ACAD', 'office'),
('Physical Plant and Facilities Office', 'PPFO', 'office'),
('Student Services', 'STUD_SERV', 'office'),
('Accounting Office', 'ACCT', 'office');

-- Standardize position names in employees and students tables
UPDATE employees SET position = 'CSC Adviser' WHERE LOWER(position) LIKE '%csc%adviser%';
UPDATE employees SET position = 'College Dean' WHERE LOWER(position) LIKE '%college%dean%';
UPDATE employees SET position = 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)' WHERE LOWER(position) LIKE '%oic%osa%';
UPDATE employees SET position = 'Center for Performing Arts Organization (CPAO)' WHERE LOWER(position) LIKE '%cpao%';
UPDATE employees SET position = 'Vice President for Academic Affairs (VPAA)' WHERE LOWER(position) LIKE '%vpaa%';
UPDATE employees SET position = 'Physical Plant and Facilities Office (PPFO)' WHERE LOWER(position) LIKE '%ppfo%';
UPDATE employees SET position = 'Executive Vice-President / Student Services (EVP)' WHERE LOWER(position) LIKE '%evp%';
UPDATE employees SET position = 'Accounting Personnel (AP)' WHERE LOWER(position) LIKE '%acp%' OR LOWER(position) LIKE '%accounting%';

UPDATE students SET position = 'College Student Council President' WHERE LOWER(position) LIKE '%csc%president%';
UPDATE students SET position = 'Supreme Student Council President' WHERE LOWER(position) LIKE '%ssc%president%';

-- Assign/update roles per department (assuming departments are set; adjust IDs as needed)
-- For each college (exclude SSC), ensure College Student Council President (student), CSC Adviser (employee), College Dean (employee)
-- Example for College of Engineering (repeat for others, replacing department name)
UPDATE students SET position = 'College Student Council President' WHERE department = 'College of Engineering' AND position IS NULL LIMIT 1;
INSERT IGNORE INTO employees (id, first_name, last_name, email, password, office, position, department) 
VALUES ('EMP_CSC_ADV_COE', 'Adviser', 'Engineering', 'adviser.coe@university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Academic Affairs', 'CSC Adviser', 'College of Engineering');
INSERT IGNORE INTO employees (id, first_name, last_name, email, password, office, position, department) 
VALUES ('EMP_DEAN_COE', 'Dean', 'Engineering', 'dean.coe@university.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Academic Affairs', 'College Dean', 'College of Engineering');

-- For SSC, ensure only Supreme Student Council President (student)
UPDATE students SET position = 'Supreme Student Council President' WHERE department = 'Supreme Student Council (SSC)' AND position IS NULL LIMIT 1;

-- Remove worthless accounts: Delete users not matching standardized departments/positions or with placeholder data
DELETE FROM students WHERE department NOT IN ('College of Arts, Social Sciences and Education', 'College of Business', 'College of Computing and Information Sciences', 'College of Criminology', 'College of Engineering', 'College of Hospitality and Tourism Management', 'College of Nursing', 'SPCF Miranda', 'Supreme Student Council (SSC)') OR position NOT IN ('College Student Council President', 'Supreme Student Council President');
DELETE FROM employees WHERE position NOT IN ('CSC Adviser', 'College Dean', 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)', 'Center for Performing Arts Organization (CPAO)', 'Vice President for Academic Affairs (VPAA)', 'Physical Plant and Facilities Office (PPFO)', 'Executive Vice-President / Student Services (EVP)', 'Accounting Personnel (AP)') OR (department IS NOT NULL AND department NOT IN ('College of Arts, Social Sciences and Education', 'College of Business', 'College of Computing and Information Sciences', 'College of Criminology', 'College of Engineering', 'College of Hospitality and Tourism Management', 'College of Nursing', 'SPCF Miranda'));

-- Example: Update/insert steps for Project Proposal (repeat for other types: Communication Letter, SAF, Facility Request)
-- Note: These are examples; adjust document_id dynamically in code if needed
-- INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id, assigned_to_student_id, status) 
-- VALUES (1, 1, 'College Student Council President', NULL, 'STU_CSC_PRES', 'pending')  -- Document Creator
-- ON DUPLICATE KEY UPDATE name = VALUES(name), assigned_to_student_id = VALUES(assigned_to_student_id);

-- INSERT INTO document_steps (document_id, step_order, name, assigned_to_employee_id) 
-- VALUES (1, 2, 'CSC Adviser', 'EMP_CSC_ADV', 'pending'),
--        (1, 3, 'Supreme Student Council President', NULL, 'STU_SSC_PRES', 'pending'),
--        (1, 4, 'College Dean', 'EMP_DEAN', 'pending'),
--        (1, 5, 'OIC-OSA', 'EMP_OIC_OSA', 'pending'),
--        (1, 6, 'CPAO', 'EMP_CPAO', 'pending'),
--        (1, 7, 'VPAA', 'EMP_VPAA', 'pending'),
--        (1, 8, 'EVP', 'EMP_EVP', 'pending')
-- ON DUPLICATE KEY UPDATE assigned_to_employee_id = VALUES(assigned_to_employee_id);

-- Similar inserts for other document types, adjusting step_order and names per your hierarchy.

-- Update position names to match expected assignments
UPDATE positions SET name = 'Officer-in-Charge, Office of Student Affairs (OIC-OSA)' WHERE id = 'POS013';
UPDATE positions SET name = 'Vice President for Academic Affairs (VPAA)' WHERE id = 'POS015';
UPDATE positions SET name = 'Accounting Personnel (AP)' WHERE id = 'POS016';

-- Add additional positions for complete role assignments
INSERT IGNORE INTO positions (id, name, unit_id) VALUES
('POS017', 'Executive Vice-President / Student Services (EVP)', 'UNT002'),
('POS018', 'Dean of Miranda', 'UNT002');