-- Migration 011: Change group column from ENUM to VARCHAR in subjects & teachers
-- ENUM is rigid and causes errors when new group values are added without migration

ALTER TABLE subjects MODIFY COLUMN `group` VARCHAR(50) DEFAULT 'General';
ALTER TABLE teachers MODIFY COLUMN `group` VARCHAR(50) DEFAULT NULL;

-- Update existing 'Common' values to 'General' (in case migration_007 was skipped)
UPDATE subjects SET `group` = 'General' WHERE `group` = 'Common';
UPDATE teachers SET `group` = 'General' WHERE `group` = 'Common';
