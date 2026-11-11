-- Exam fee collection (admin + exam controller collect; QR on admit card shows payment status)
CREATE TABLE IF NOT EXISTS exam_fees (
  id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_user_id INT(11) NOT NULL,
  year YEAR(4) NOT NULL,
  exam_name VARCHAR(100) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('cash','online') DEFAULT 'cash',
  transaction_id VARCHAR(100) DEFAULT NULL,
  collected_by INT(11) NOT NULL,
  collected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_student_exam (student_user_id, year, exam_name),
  KEY idx_year_exam (year, exam_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
