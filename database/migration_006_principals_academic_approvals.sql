-- Migration 006: Add principals and academic_approvals tables

CREATE TABLE principals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    message TEXT DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE academic_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    heading VARCHAR(255) NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    image_width INT DEFAULT NULL,
    image_height INT DEFAULT NULL,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB;
