USE prossor_colegio;

-- Nuevas estructuras incrementales: no modifica ni elimina registros existentes.
CREATE TABLE IF NOT EXISTS login_throttles (
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  PRIMARY KEY (email_hash, ip_hash),
  INDEX (blocked_until)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_hash CHAR(64) NOT NULL UNIQUE,
  code_type ENUM('parent_student','teacher_group') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  used_by BIGINT UNSIGNED NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (code_type, expires_at),
  INDEX (entity_id, code_type)
) ENGINE=InnoDB;

