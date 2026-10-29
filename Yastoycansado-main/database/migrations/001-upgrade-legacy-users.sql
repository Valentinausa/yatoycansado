USE prossor_colegio;
-- Importa esta migración solo si la base anterior tenía la tabla users de database.sql.
ALTER TABLE users
  CHANGE COLUMN password password_hash VARCHAR(255) NOT NULL,
  ADD COLUMN institution_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN role ENUM('student','parent','teacher','admin') NOT NULL DEFAULT 'student' AFTER password_hash,
  ADD COLUMN grade_level TINYINT UNSIGNED NULL AFTER role,
  ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER grade_level,
  ADD COLUMN last_login_at DATETIME NULL AFTER created_at;
ALTER TABLE users ADD CONSTRAINT fk_users_institution FOREIGN KEY(institution_id) REFERENCES institutions(id) ON DELETE SET NULL;
