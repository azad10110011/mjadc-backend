-- Migration 009: Add pds_id and mpo_index columns to teachers and staff tables

ALTER TABLE teachers ADD COLUMN pds_id VARCHAR(50) DEFAULT NULL AFTER sort_order;
ALTER TABLE teachers ADD COLUMN mpo_index VARCHAR(50) DEFAULT NULL AFTER pds_id;

ALTER TABLE staff ADD COLUMN pds_id VARCHAR(50) DEFAULT NULL AFTER sort_order;
ALTER TABLE staff ADD COLUMN mpo_index VARCHAR(50) DEFAULT NULL AFTER pds_id;
