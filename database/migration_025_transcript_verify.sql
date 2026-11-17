-- Migration 025: Transcript QR verification
-- transcript_verifications: one row = admin has turned ON online verification
--   for that student (students.id) on that exam (exams.id). No row = OFF.
-- app_secrets: server-side HMAC secret that signs transcript QR links
--   (kept out of site_settings because /api/settings/{key} is public).
-- NOTE: routes/admin/transcript-verify.php also creates both tables on demand,
--   so running this file is optional but recommended.
CREATE TABLE IF NOT EXISTS transcript_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    enabled_by INT NULL,
    enabled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_exam (student_id, exam_id),
    KEY idx_exam (exam_id)
);

CREATE TABLE IF NOT EXISTS app_secrets (
    name VARCHAR(64) PRIMARY KEY,
    value VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
