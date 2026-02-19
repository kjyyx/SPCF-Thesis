-- Create materials_steps table
USE spcf_thesis_db;

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