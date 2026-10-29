USE prossor_colegio;

-- Introduce a platform-wide role without changing existing user assignments.
ALTER TABLE users
  MODIFY role ENUM('student','parent','teacher','admin','superadmin')
  NOT NULL DEFAULT 'student';
