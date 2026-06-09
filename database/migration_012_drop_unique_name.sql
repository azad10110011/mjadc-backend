-- Migration 012: Remove UNIQUE constraint on subjects.name
-- and add composite UNIQUE on (name, group) to allow same name in different groups

ALTER TABLE subjects DROP INDEX name;
ALTER TABLE subjects ADD UNIQUE INDEX unique_name_group (name, `group`);
