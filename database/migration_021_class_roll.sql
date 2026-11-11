-- Migration: class_roll column for students (used in student ID generation: YY + group code + roll)
ALTER TABLE students ADD COLUMN class_roll VARCHAR(10) DEFAULT NULL AFTER academic_session;

-- blood_group column (Session 22)
ALTER TABLE students ADD COLUMN IF NOT EXISTS blood_group VARCHAR(10) DEFAULT NULL AFTER class_roll;
