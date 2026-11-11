-- Migration: Exam subject paper configuration per exam
-- Allows admin to set which papers are used for each subject in each exam
-- and how marks are calculated in transcript (separate or average)

CREATE TABLE IF NOT EXISTS exam_subject_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    subject_id INT NOT NULL,
    paper_selection ENUM('single', 'all') NOT NULL DEFAULT 'all',
    selected_paper_id INT DEFAULT NULL,
    calculation_mode ENUM('separate', 'average') NOT NULL DEFAULT 'separate',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (selected_paper_id) REFERENCES subject_papers(id) ON DELETE SET NULL,
    UNIQUE KEY unique_exam_subject (exam_id, subject_id)
);
