CREATE TABLE password_recovery_versions (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  changed_at DATETIME NOT NULL,
  CONSTRAINT fk_recovery_version_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  credential_stamp CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  mail_ciphertext VARBINARY(2048) NULL,
  mail_nonce VARBINARY(12) NULL,
  mail_tag VARBINARY(16) NULL,
  key_version INT UNSIGNED NOT NULL,
  mail_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  mail_sent_at DATETIME NULL,
  mail_next_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_reset_user (user_id,created_at),
  KEY idx_reset_queue (mail_next_at,expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
