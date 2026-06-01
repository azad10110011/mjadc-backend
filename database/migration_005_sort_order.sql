ALTER TABLE teachers ADD COLUMN sort_order INT DEFAULT 0 AFTER photo_path;
ALTER TABLE staff ADD COLUMN sort_order INT DEFAULT 0 AFTER photo_path;
ALTER TABLE co_curricular ADD COLUMN sort_order INT DEFAULT 0 AFTER photo_path;
ALTER TABLE students ADD COLUMN sort_order INT DEFAULT 0 AFTER photo_path;
