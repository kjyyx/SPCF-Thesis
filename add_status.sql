USE SPCF_Thesis_db;
ALTER TABLE administrators ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';
ALTER TABLE employees ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';
ALTER TABLE students ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';