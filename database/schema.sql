-- MJADC College Management System - Database Schema
-- MySQL 8.x



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
) ENGINE=InnoDB;

-- User roles (many-to-many)
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('student', 'teacher', 'staff', 'exam_controller', 'administration', 'principal', 'admin') NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_role (user_id, role)
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- Exams
CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    class ENUM('11th', '12th') NOT NULL,
    exam_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_exam (year, class, exam_name)
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- Leave allocations
CREATE TABLE leave_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_type ENUM('teacher', 'staff', 'principal') NOT NULL,
    leave_type ENUM('casual', 'medical', 'maternity', 'without_pay') NOT NULL,
    total_days INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_allocation (role_type, leave_type)
) ENGINE=InnoDB;

-- Leave taken tracker
CREATE TABLE leave_taken (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    year YEAR NOT NULL,
    leave_type ENUM('casual', 'medical', 'maternity', 'without_pay') NOT NULL,
    days_taken INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_leave_taken (user_id, year, leave_type)
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- Routines
CREATE TABLE routines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class ENUM('11th', '12th') NOT NULL,
    section VARCHAR(10),
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Downloadable forms
CREATE TABLE downloadable_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_name VARCHAR(100) NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Gallery images
CREATE TABLE gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caption VARCHAR(255),
    event_name VARCHAR(100),
    photo_path VARCHAR(255) NOT NULL,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Events calendar
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Governing body members
CREATE TABLE governing_body (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    position VARCHAR(100) DEFAULT NULL,
    mobile VARCHAR(15) DEFAULT NULL,
    photo_path VARCHAR(255),
    sort_order INT DEFAULT 0
) ENGINE=InnoDB;

-- Co-curricular members
CREATE TABLE co_curricular (
    id INT AUTO_INCREMENT PRIMARY KEY,
    club ENUM('BNCC', 'Rover Scout', 'Science Club', 'Debating Club') NOT NULL,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100),
    mobile VARCHAR(15),
    photo_path VARCHAR(255)
) ENGINE=InnoDB;

-- Annual reports
CREATE TABLE annual_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year YEAR NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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

-- Migration: Add new columns for enhanced teacher/staff management
ALTER TABLE teachers
  ADD COLUMN name_bangla VARCHAR(255) DEFAULT NULL AFTER name,
  ADD COLUMN name_english VARCHAR(255) DEFAULT NULL AFTER name_bangla,
  ADD COLUMN first_mpo_date DATE DEFAULT NULL AFTER joining_date,
  ADD COLUMN nid_number VARCHAR(50) DEFAULT NULL AFTER first_mpo_date,
  ADD COLUMN whatsapp_number VARCHAR(15) DEFAULT NULL AFTER mobile,
  ADD COLUMN present_address TEXT DEFAULT NULL AFTER email,
  ADD COLUMN permanent_address TEXT DEFAULT NULL AFTER present_address;

ALTER TABLE staff
  ADD COLUMN name_bangla VARCHAR(255) DEFAULT NULL AFTER name,
  ADD COLUMN name_english VARCHAR(255) DEFAULT NULL AFTER name_bangla,
  ADD COLUMN first_mpo_date DATE DEFAULT NULL AFTER joining_date,
  ADD COLUMN nid_number VARCHAR(50) DEFAULT NULL AFTER first_mpo_date,
  ADD COLUMN whatsapp_number VARCHAR(15) DEFAULT NULL AFTER mobile,
  ADD COLUMN present_address TEXT DEFAULT NULL AFTER email,
  ADD COLUMN permanent_address TEXT DEFAULT NULL AFTER present_address;

-- Site settings (key-value)
CREATE TABLE site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Page content (static pages)
CREATE TABLE page_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255),
    content TEXT NOT NULL,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
