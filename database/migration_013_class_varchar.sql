-- Migration 013: Change students.class from ENUM to VARCHAR to support "Old" (ex-students)
ALTER TABLE students MODIFY class VARCHAR(50) NOT NULL DEFAULT '11th';
