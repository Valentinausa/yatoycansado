USE prossor_colegio;

ALTER TABLE subscription_plans ADD COLUMN IF NOT EXISTS duration_days SMALLINT UNSIGNED NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS payment_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NOT NULL,
 reference VARCHAR(80) NOT NULL UNIQUE,
 provider VARCHAR(30) NOT NULL DEFAULT 'wompi',
 environment ENUM('test','prod') NOT NULL DEFAULT 'test',
 currency CHAR(3) NOT NULL DEFAULT 'COP',
 amount_in_cents INT UNSIGNED NOT NULL,
 status ENUM('pending','approved','declined','voided','error') NOT NULL DEFAULT 'pending',
 provider_transaction_id VARCHAR(120) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_transaction (provider,provider_transaction_id),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (subscription_id) REFERENCES user_subscriptions(id) ON DELETE CASCADE,
 INDEX (user_id,status), INDEX (subscription_id,status)
) ENGINE=InnoDB;
