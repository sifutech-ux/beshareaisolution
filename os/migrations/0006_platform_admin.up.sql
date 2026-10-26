-- Additive platform administration. No automatic grants, registration or messaging.
-- Install through prepare-platform-admin.php; tenant roles and ownership stay intact.
CREATE TABLE platform_admins (
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'owner',
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_platform_admin_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_platform_admin_role CHECK (role = 'owner'),
  CONSTRAINT chk_platform_admin_status CHECK (status IN ('active','revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_business_access (
  business_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (business_id),
  CONSTRAINT fk_platform_access_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE RESTRICT,
  CONSTRAINT fk_platform_access_actor FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_platform_access_status CHECK (status IN ('active','suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_whatsapp_operations (
  connection_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL,
  error_code VARCHAR(64) NULL,
  pin_ciphertext VARBINARY(128) NULL,
  pin_nonce BINARY(12) NULL,
  pin_tag BINARY(16) NULL,
  pin_key_version SMALLINT UNSIGNED NULL,
  registration_started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (connection_id),
  UNIQUE KEY uq_platform_operation (operation_id),
  KEY ix_platform_operation_business (business_id,status),
  CONSTRAINT fk_platform_operation_connection FOREIGN KEY (business_id,connection_id)
    REFERENCES whatsapp_connections (business_id,id) ON DELETE RESTRICT,
  CONSTRAINT fk_platform_operation_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_platform_operation_status CHECK (status IN ('checking','registering','succeeded','blocked','unknown')),
  CONSTRAINT chk_platform_operation_pin CHECK (
    (pin_ciphertext IS NULL AND pin_nonce IS NULL AND pin_tag IS NULL AND pin_key_version IS NULL)
    OR (pin_ciphertext IS NOT NULL AND pin_nonce IS NOT NULL AND pin_tag IS NOT NULL AND pin_key_version IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
