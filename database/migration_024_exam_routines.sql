-- Migration 024: Exam Routine (date-wise exam schedule per class/year/exam)
-- subjects column: JSON array of subject names scheduled on that date.
CREATE TABLE IF NOT EXISTS exam_routines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year VARCHAR(10) NOT NULL,
    class VARCHAR(50) NOT NULL,
    exam_name VARCHAR(100) NOT NULL,
    exam_date DATE NOT NULL,
    subjects TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_routine (year, class, exam_name)
);
