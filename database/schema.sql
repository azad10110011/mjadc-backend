-- MJADC College Management System - Database Schema
-- MySQL 8.x

-- =============================================
-- MIGRATION: Convert existing database to utf8mb4
-- Run these if you already have tables without Bangla support:
-- =============================================
-- ALTER DATABASE mjadc_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- 
-- SELECT CONCAT('ALTER TABLE ', TABLE_NAME, ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
-- FROM information_schema.TABLES
-- WHERE TABLE_SCHEMA = 'mjadc_db' AND TABLE_TYPE = 'BASE TABLE';
-- =============================================

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    date_of_birth DATE DEFAULT NULL,
    status ENUM('active', 'frozen') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User roles (many-to-many)
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('student', 'teacher', 'staff', 'exam_controller', 'administration', 'principal', 'admin') NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Students
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    father_name VARCHAR(100),
    mother_name VARCHAR(100),
    class ENUM('11th', '12th') NOT NULL,
    section VARCHAR(10),
    joining_year YEAR NOT NULL,
    mobile VARCHAR(15),
    email VARCHAR(100),
    gender ENUM('male', 'female') NOT NULL,
    address TEXT,
    photo_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teachers
CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    designation VARCHAR(50) NOT NULL,
    subject VARCHAR(50),
    joining_date DATE NOT NULL,
    mobile VARCHAR(15),
    email VARCHAR(100),
    photo_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff
CREATE TABLE staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    designation VARCHAR(50) NOT NULL,
    subject VARCHAR(50),
    joining_date DATE NOT NULL,
    mobile VARCHAR(15),
    email VARCHAR(100),
    photo_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notices
CREATE TABLE notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT,
    status ENUM('published', 'draft') DEFAULT 'draft',
    pdf_path VARCHAR(255),
    published_at TIMESTAMP NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exams
CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    class ENUM('11th', '12th') NOT NULL,
    exam_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_exam (year, class, exam_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exam results
CREATE TABLE exam_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    subject VARCHAR(50) NOT NULL,
    mcq DECIMAL(5,2) DEFAULT 0,
    cq DECIMAL(5,2) DEFAULT 0,
    practical DECIMAL(5,2) DEFAULT 0,
    total DECIMAL(6,2) GENERATED ALWAYS AS (mcq + cq + practical) STORED,
    grade VARCHAR(2),
    gpa DECIMAL(3,2),
    status ENUM('draft', 'approved', 'published') DEFAULT 'draft',
    uploaded_by INT,
    approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (exam_id) REFERENCES exams(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    UNIQUE KEY unique_result (student_id, exam_id, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE exam_results
  MODIFY COLUMN grade VARCHAR(10) DEFAULT NULL,
  ADD COLUMN absent_in TEXT DEFAULT NULL AFTER grade;

-- Subject parts configuration (dynamic mark distribution per subject)
CREATE TABLE subject_parts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(50) NOT NULL,
    part_name VARCHAR(50) NOT NULL,
    full_mark DECIMAL(5,2) NOT NULL DEFAULT 0,
    pass_mark DECIMAL(5,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_subject_part (subject, part_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add registration_no, academic_session, student_type for transcripts
ALTER TABLE students
  ADD COLUMN registration_no VARCHAR(20) DEFAULT NULL AFTER student_id,
  ADD COLUMN academic_session VARCHAR(20) DEFAULT NULL AFTER joining_year,
  ADD COLUMN student_type ENUM('Regular', 'Irregular', 'Improvement') DEFAULT 'Regular' AFTER academic_session,
  ADD COLUMN optional_subject VARCHAR(50) DEFAULT NULL AFTER student_type;

-- Add published_at to exam_results for transcript publish date
ALTER TABLE exam_results
  ADD COLUMN published_at TIMESTAMP NULL DEFAULT NULL AFTER status;

-- Result change log for tracking user actions
CREATE TABLE result_changelog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_result_id INT DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    old_data TEXT DEFAULT NULL,
    new_data TEXT DEFAULT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_result_id) REFERENCES exam_results(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: Add parts_data JSON column for dynamic subject parts
ALTER TABLE exam_results
  ADD COLUMN parts_data TEXT DEFAULT NULL AFTER practical,
  ADD COLUMN total_new DECIMAL(6,2) DEFAULT 0 AFTER grade;

UPDATE exam_results SET total_new = total;

ALTER TABLE exam_results
  DROP COLUMN total;

ALTER TABLE exam_results
  CHANGE COLUMN total_new total DECIMAL(6,2) DEFAULT 0 AFTER grade;

-- Populate parts_data from existing columns for backward compatibility
UPDATE exam_results SET parts_data = JSON_OBJECT(
  'mcq', COALESCE(mcq, 0),
  'cq', COALESCE(cq, 0),
  'practical', COALESCE(practical, 0)
) WHERE parts_data IS NULL;

-- Tuition fees
CREATE TABLE tuition_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    year YEAR NOT NULL,
    month TINYINT NOT NULL,
    amount_due DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_at TIMESTAMP NULL,
    payment_method ENUM('cash', 'online') DEFAULT 'online',
    transaction_id VARCHAR(100),
    waiver_amount DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    UNIQUE KEY unique_fee (student_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave allocations
CREATE TABLE leave_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_type ENUM('teacher', 'staff', 'principal') NOT NULL,
    leave_type ENUM('casual', 'medical', 'maternity', 'without_pay') NOT NULL,
    total_days INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_allocation (role_type, leave_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User roles (many-to-many)
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('student', 'teacher', 'staff', 'exam_controller', 'administration', 'principal', 'admin') NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave applications
CREATE TABLE leave_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    applicant_role ENUM('teacher', 'staff', 'principal') NOT NULL,
    leave_type ENUM('casual', 'medical', 'maternity', 'without_pay') NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    reason TEXT,
    document_path VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (applicant_id) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admissions
CREATE TABLE admissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_name VARCHAR(100) NOT NULL,
    dob DATE,
    gender ENUM('male', 'female'),
    religion VARCHAR(50),
    nationality VARCHAR(50) DEFAULT 'Bangladeshi',
    father_name VARCHAR(100),
    mother_name VARCHAR(100),
    guardian_contact VARCHAR(15),
    previous_institution VARCHAR(100),
    previous_board VARCHAR(50),
    previous_roll VARCHAR(20),
    passing_year YEAR,
    previous_gpa DECIMAL(4,2),
    programme VARCHAR(20) NOT NULL,
    class_group VARCHAR(50),
    photo_path VARCHAR(255),
    certificate_path VARCHAR(255),
    mobile VARCHAR(15),
    email VARCHAR(100),
    fee_paid TINYINT(1) DEFAULT 0,
    transaction_id VARCHAR(100),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Syllabus
CREATE TABLE syllabus (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class VARCHAR(20) NOT NULL,
    department VARCHAR(50),
    subject VARCHAR(50),
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Routines
CREATE TABLE routines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class ENUM('11th', '12th') NOT NULL,
    section VARCHAR(10),
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Downloadable forms
CREATE TABLE downloadable_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_name VARCHAR(100) NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gallery images
CREATE TABLE gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caption VARCHAR(255),
    event_name VARCHAR(100),
    photo_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events calendar
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Governing body members
CREATE TABLE governing_body (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    position VARCHAR(100) NOT NULL,
    photo_path VARCHAR(255),
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Co-curricular members
CREATE TABLE co_curricular (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club ENUM('BNCC', 'Rover Scout', 'Science Club', 'Debating Club') NOT NULL,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100),
    mobile VARCHAR(15),
    photo_path VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Annual reports
CREATE TABLE annual_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: Add new columns for enhanced student management
ALTER TABLE students
  ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER mother_name,
  ADD COLUMN parent_mobile VARCHAR(15) DEFAULT NULL AFTER mobile,
  ADD COLUMN whatsapp VARCHAR(15) DEFAULT NULL AFTER parent_mobile,
  ADD COLUMN present_address TEXT DEFAULT NULL AFTER address,
  ADD COLUMN permanent_address TEXT DEFAULT NULL AFTER present_address,
  ADD COLUMN student_group ENUM('Science', 'Business Studies', 'Humanities') DEFAULT NULL AFTER permanent_address,
  ADD COLUMN compulsory_subjects TEXT DEFAULT NULL AFTER student_group,
  ADD COLUMN selective_subjects TEXT DEFAULT NULL AFTER compulsory_subjects;

-- Site settings (key-value)
CREATE TABLE site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page content (static pages)
CREATE TABLE page_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255),
    content TEXT NOT NULL,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment transactions
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(100) UNIQUE NOT NULL,
    student_id INT,
    admission_id INT,
    amount DECIMAL(10,2) NOT NULL,
    payment_type ENUM('tuition_fee', 'admission_fee') NOT NULL,
    payment_method ENUM('online', 'cash') DEFAULT 'online',
    month_paid TINYINT,
    year_paid YEAR,
    status ENUM('pending', 'success', 'failed') DEFAULT 'pending',
    bank_transaction_id VARCHAR(100),
    receipt_generated TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (admission_id) REFERENCES admissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification log
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    type ENUM('sms', 'email') NOT NULL,
    subject VARCHAR(255),
    message TEXT NOT NULL,
    sent_status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO site_settings (setting_key, setting_value) VALUES
('footer_text', '© 2026-MJADC. WebSite Created & Designed By MAK Azad, Lecturer (ICT)'),
('college_name', 'Miah Jinnah Alam Degree College'),
('college_domain', 'mjadc.ac.bd'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_email', ''),
('smtp_password', ''),
('sms_provider', ''),
('sms_api_key', ''),
('sonali_merchant_id', ''),
('sonali_api_key', ''),
('sonali_sandbox', '1');

-- Insert default leave allocations
INSERT INTO leave_allocations (role_type, leave_type, total_days) VALUES
('teacher', 'casual', 14),
('teacher', 'medical', 14),
('teacher', 'maternity', 120),
('teacher', 'without_pay', 0),
('staff', 'casual', 14),
('staff', 'medical', 14),
('staff', 'maternity', 120),
('staff', 'without_pay', 0),
('principal', 'casual', 14),
('principal', 'medical', 14),
('principal', 'maternity', 120),
('principal', 'without_pay', 0);

-- Insert default page content
INSERT INTO page_content (page_key, title, content) VALUES
('home', 'Home', '<p>Welcome to Miah Jinnah Alam Degree College (MJADC).</p>'),
('about', 'About Us', '<p>Miah Jinnah Alam Degree College (MJADC) was established with a vision to provide quality higher education to students in the region.</p>'),
('scholarship', 'Scholarship Information', '<p>The college offers various scholarships to support meritorious and needy students.</p>'),
('admission_info', 'Admission Information', '<p>Admission information and eligibility criteria.</p>'),
('career_club', 'Career Club', '<p>The MJADC Career Club helps students prepare for their professional futures.</p>'),
('contact', 'Contact Us', '<p>Contact information for Miah Jinnah Alam Degree College.</p>'),
('principal', 'Principal & Vice-Principal', '<p>Information about the Principal and Vice-Principal of MJADC.</p>'),
('governing_body', 'Governing Body', '<p>The Governing Body of Miah Jinnah Alam Degree College.</p>'),
('teachers_council', 'Teachers Council', '<p>The Teachers Council of Miah Jinnah Alam Degree College.</p>'),
('departments_intro', 'Departments Introduction', '<p>Academic departments at Miah Jinnah Alam Degree College.</p>'),
('co_curricular_intro', 'Co-curricular Introduction', '<p>Co-curricular activities at Miah Jinnah Alam Degree College.</p>'),
('academic_forms', 'Academic Forms', '<p>Downloadable academic forms for students.</p>'),
('annual_reports', 'Annual Reports', '<p>Annual reports of Miah Jinnah Alam Degree College.</p>'),
('gallery_intro', 'Gallery', '<p>Photo gallery of Miah Jinnah Alam Degree College.</p>'),
('events_intro', 'Events', '<p>Events at Miah Jinnah Alam Degree College.</p>'),
('notices_intro', 'Notices', '<p>Notice board of Miah Jinnah Alam Degree College.</p>');

-- Insert default admin user (password: admin123)
INSERT INTO users (name, email, password_hash, gender) VALUES
('System Admin', 'admin@mjadc.ac.bd', '$2y$12$gpB2J1GPrvl7Pceog7tHme8LUIm9x0ElaqrII4soTw2W00.lE76ue', 'male');

INSERT INTO user_roles (user_id, role) VALUES (1, 'admin');

-- Subjects catalog (manageable by admin)
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO subjects (name) VALUES
('Bangla'), ('English'), ('ICT'), ('Political Science'),
('Economics'), ('Geography'), ('Philosophy'), ('Sociology'),
('Social Welfare'), ('History'), ('Islamic History'),
('Islamic Studies'), ('Psychology'), ('Statistics'),
('Agriculture'), ('Home Economics'), ('Physics'), ('Chemistry'),
('Biology'), ('Higher Math'), ('Management'), ('Marketing'),
('Production Management & Marketing'), ('Accounting'),
('Finance Banking & Insurance'), ('Finance & Banking');

-- Teacher subjects (multiple subjects per teacher)
CREATE TABLE IF NOT EXISTS teacher_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject VARCHAR(50) NOT NULL,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_teacher_subject (teacher_id, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing data
INSERT IGNORE INTO teacher_subjects (teacher_id, subject)
SELECT id, subject FROM teachers WHERE subject IS NOT NULL AND subject != '';

-- Migration: Add type column to subjects (public/result/both)
ALTER TABLE subjects
  ADD COLUMN type ENUM('public','result','both') NOT NULL DEFAULT 'public' AFTER name;

-- Mark existing subjects as public
UPDATE subjects SET type = 'public';

-- Migration: Add type column to teacher_subjects
ALTER TABLE teacher_subjects
  ADD COLUMN type ENUM('public','result') NOT NULL DEFAULT 'public' AFTER subject;

-- Change unique constraint to include type
ALTER TABLE teacher_subjects DROP INDEX unique_teacher_subject;
ALTER TABLE teacher_subjects ADD UNIQUE KEY unique_teacher_subject_type (teacher_id, subject, type);

-- Seed result management subjects (subjects with paper variants)
INSERT IGNORE INTO subjects (name, type) VALUES
('Bangla-1', 'result'), ('Bangla-2', 'result'),
('English-1', 'result'), ('English-2', 'result'),
('ICT-1', 'result'), ('ICT-2', 'result'),
('Physics-1', 'result'), ('Physics-2', 'result'),
('Chemistry-1', 'result'), ('Chemistry-2', 'result'),
('Biology-1', 'result'), ('Biology-2', 'result'),
('Higher Math-1', 'result'), ('Higher Math-2', 'result');

-- Migration: Add parent_id to link result papers to parent subjects
ALTER TABLE subjects
  ADD COLUMN parent_id INT DEFAULT NULL AFTER type,
  ADD FOREIGN KEY (parent_id) REFERENCES subjects(id) ON DELETE SET NULL;

-- Link existing result subjects to their parent by naming convention
UPDATE subjects s1
  JOIN subjects s2 ON s2.name = SUBSTRING_INDEX(s1.name, '-', 1)
  SET s1.parent_id = s2.id
  WHERE s1.type = 'result' AND s2.type = 'public';
