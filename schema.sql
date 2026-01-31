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
ALTER TABLE events ADD COLUMN venue VARCHAR(200) NULL AFTER description;

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

-- Add new columns for project proposals
ALTER TABLE documents ADD COLUMN venue VARCHAR(200) NULL AFTER description;
ALTER TABLE documents ADD COLUMN schedule_summary TEXT NULL AFTER venue;
ALTER TABLE documents ADD COLUMN earliest_start_time DATETIME NULL AFTER schedule_summary;

-- Add data column for storing document data as JSON
ALTER TABLE documents ADD COLUMN data JSON NULL AFTER earliest_start_time;

-- Add date column for due dates
ALTER TABLE documents ADD COLUMN date DATE NULL AFTER data;

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