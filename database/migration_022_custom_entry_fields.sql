-- Migration 022: Custom entry fields for Add Student / Teacher / Staff forms
CREATE TABLE IF NOT EXISTS custom_entry_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target VARCHAR(20) NOT NULL COMMENT 'student | teacher | staff',
    label VARCHAR(150) NOT NULL,
    field_type VARCHAR(20) NOT NULL DEFAULT 'text' COMMENT 'text | number | date | dropdown | yes_no',
    options TEXT NULL COMMENT 'comma-separated options for dropdown type',
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store submitted values as JSON keyed by field label
ALTER TABLE students ADD COLUMN custom_fields TEXT NULL;
ALTER TABLE teachers ADD COLUMN custom_fields TEXT NULL;
ALTER TABLE staff ADD COLUMN custom_fields TEXT NULL;
