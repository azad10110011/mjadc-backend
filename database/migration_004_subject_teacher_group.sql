-- Migration: Add group column to subjects and teachers tables
-- Groups: Science, Business Studies, Humanities, Common

ALTER TABLE subjects
  ADD COLUMN `group` ENUM('Science', 'Business Studies', 'Humanities', 'Common') DEFAULT 'Common' AFTER type;

ALTER TABLE teachers
  ADD COLUMN `group` ENUM('Science', 'Business Studies', 'Humanities', 'Common') DEFAULT NULL AFTER subject;
