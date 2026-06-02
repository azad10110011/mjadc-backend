-- Migration 007: Rename Common to General, add BMT group

-- Update teachers: change ENUM and migrate existing data
ALTER TABLE teachers MODIFY COLUMN `group` ENUM('Science', 'Business Studies', 'Humanities', 'General', 'BMT') DEFAULT NULL;
UPDATE teachers SET `group` = 'General' WHERE `group` = 'Common';

-- Update subjects: change ENUM and migrate existing data
ALTER TABLE subjects MODIFY COLUMN `group` ENUM('Science', 'Business Studies', 'Humanities', 'General', 'BMT') DEFAULT 'General';
UPDATE subjects SET `group` = 'General' WHERE `group` = 'Common';
