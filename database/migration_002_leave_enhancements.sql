-- Migration: Leave Management Enhancements
-- Adds per-user allocations, yearly/lifetime period tracking, maternity limit

ALTER TABLE leave_allocations
  ADD COLUMN user_id INT NULL AFTER role_type,
  ADD COLUMN period ENUM('yearly', 'lifetime') DEFAULT 'yearly' AFTER total_days,
  ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE leave_allocations DROP INDEX unique_allocation;
ALTER TABLE leave_allocations ADD UNIQUE KEY unique_allocation (role_type, leave_type, user_id, period);

ALTER TABLE leave_taken
  ADD COLUMN period ENUM('yearly', 'lifetime') DEFAULT 'yearly' AFTER leave_type;

ALTER TABLE leave_taken DROP INDEX unique_leave_taken;
ALTER TABLE leave_taken ADD UNIQUE KEY unique_leave_taken (user_id, year, leave_type, period);
