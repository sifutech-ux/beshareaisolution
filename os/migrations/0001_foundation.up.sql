-- Foundation 0001. DDL sahaja. Runner menulis schema_migrations selepas semua CREATE berjaya.
-- Pangkalan data yang dibenarkan: u879723783_beshare_os
--
-- Jadual: schema_migrations, users, businesses, business_users,
--   whatsapp_connections, onboarding_sessions, webhook_events, audit_logs
-- Trigger: bi_businesses_active_owner, bi_business_users_owner,
--   bu_business_users_owner, bd_business_users_owner, bu_businesses_active_owner
--
-- Dua trigger tambahan berbanding senarai tiga nama yang awal:
-- bi_businesses_active_owner menolak sisipan terus sebagai active.
-- bd_business_users_owner menolak pemadaman pemilik semasa status active
-- atau suspended.
-- Trigger tidak mengemas kini jadual lain, supaya MariaDB tidak menolaknya
-- dengan ralat 1442 semasa semakan foreign key.
--
-- Mesin status perniagaan:
--   Sisipan hanya provisioning.
--   provisioning -> active atau suspended dibenarkan jika owner_user_id
--   tidak berubah dalam pernyataan yang sama dan tepat satu keahlian owner
--   sepadan.
--   active <-> suspended dibenarkan. owner_user_id tidak berubah.
--   active atau suspended -> provisioning dibenarkan. owner_user_id tidak
--   berubah. Ini membuka tetingkap pemindahan pemilikan, bukan menukar pemilik.
--   owner_user_id hanya berubah apabila status kekal provisioning.
--   Peranan owner pada business_users hanya berubah semasa provisioning.
--   Kemas kini business_users menyemak status perniagaan lama dan baharu.
--   Memindahkan baris owner keluar dari active atau suspended ditolak,
--   walaupun destinasi masih provisioning.
--   Perubahan pemilik dan pertukaran status dalam satu pernyataan ditolak.
--
-- Pemindahan pemilikan: tukar ke provisioning, ubah keahlian, tetapkan
-- owner_user_id semasa masih provisioning, kemudian kembali ke active
-- atau suspended.

CREATE TABLE schema_migrations (
  version VARCHAR(16) NOT NULL,
  name VARCHAR(128) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(191) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT chk_users_status CHECK (status IN ('active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE businesses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  name VARCHAR(191) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'provisioning',
  owner_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_businesses_public (public_id),
  KEY ix_businesses_owner (owner_user_id),
  CONSTRAINT fk_businesses_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
  CONSTRAINT chk_businesses_status CHECK (status IN ('provisioning', 'active', 'suspended')),
  CONSTRAINT chk_businesses_owner_present CHECK (
    status = 'provisioning' OR owner_user_id IS NOT NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER bi_businesses_active_owner
BEFORE INSERT ON businesses
FOR EACH ROW
BEGIN
  IF NEW.status <> 'provisioning' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'business_must_start_provisioning';
  END IF;
END;

CREATE TABLE business_users (
  business_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL,
  owner_guard BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN role = 'owner' THEN business_id ELSE NULL END) STORED,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (business_id, user_id),
  UNIQUE KEY uq_one_owner (owner_guard),
  KEY ix_membership_user (user_id),
  CONSTRAINT fk_membership_business FOREIGN KEY (business_id) REFERENCES businesses (id),
  CONSTRAINT fk_membership_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT chk_membership_role CHECK (role IN ('owner', 'admin', 'staff'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER bi_business_users_owner
BEFORE INSERT ON business_users
FOR EACH ROW
BEGIN
  DECLARE biz_status VARCHAR(16);
  SELECT status INTO biz_status FROM businesses WHERE id = NEW.business_id;
  IF biz_status IN ('active', 'suspended') AND NEW.role = 'owner' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_change_requires_provisioning';
  END IF;
END;

CREATE TRIGGER bu_business_users_owner
BEFORE UPDATE ON business_users
FOR EACH ROW
BEGIN
  DECLARE old_status VARCHAR(16);
  DECLARE new_status VARCHAR(16);
  SELECT status INTO old_status FROM businesses WHERE id = OLD.business_id;
  SELECT status INTO new_status FROM businesses WHERE id = NEW.business_id;
  IF (old_status IN ('active', 'suspended') OR new_status IN ('active', 'suspended'))
    AND (OLD.role = 'owner' OR NEW.role = 'owner') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_change_requires_provisioning';
  END IF;
END;

CREATE TRIGGER bd_business_users_owner
BEFORE DELETE ON business_users
FOR EACH ROW
BEGIN
  DECLARE biz_status VARCHAR(16);
  SELECT status INTO biz_status FROM businesses WHERE id = OLD.business_id;
  IF biz_status IN ('active', 'suspended') AND OLD.role = 'owner' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_delete_requires_provisioning';
  END IF;
END;

CREATE TRIGGER bu_businesses_active_owner
BEFORE UPDATE ON businesses
FOR EACH ROW
BEGIN
  DECLARE owner_count INT;
  DECLARE matched INT;
  IF NOT (OLD.owner_user_id <=> NEW.owner_user_id)
    AND NOT (OLD.status = 'provisioning' AND NEW.status = 'provisioning') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_change_requires_provisioning';
  END IF;
  IF NEW.status IN ('active', 'suspended') THEN
    IF NEW.owner_user_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_owner_required';
    END IF;
    SELECT COUNT(*) INTO owner_count
      FROM business_users
      WHERE business_id = NEW.id AND role = 'owner';
    SELECT COUNT(*) INTO matched
      FROM business_users
      WHERE business_id = NEW.id AND user_id = NEW.owner_user_id AND role = 'owner';
    IF owner_count <> 1 OR matched <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'owner_mismatch';
    END IF;
  END IF;
END;

CREATE TABLE whatsapp_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  waba_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  phone_number_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  display_phone VARCHAR(32) NULL,
  token_state VARCHAR(16) NOT NULL,
  webhook_state VARCHAR(24) NOT NULL DEFAULT 'not_subscribed',
  registration_state VARCHAR(24) NOT NULL DEFAULT 'unknown',
  messaging_state VARCHAR(32) NOT NULL DEFAULT 'unverified',
  token_kind VARCHAR(32) NOT NULL DEFAULT 'unknown',
  token_ciphertext VARBINARY(2048) NULL,
  token_nonce BINARY(12) NULL,
  token_tag BINARY(16) NULL,
  token_key_version SMALLINT UNSIGNED NULL,
  token_expires_at DATETIME NULL,
  refresh_supported TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_verified_at DATETIME NULL,
  last_error_code VARCHAR(64) NULL,
  subscribed_at DATETIME NULL,
  disconnected_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_tenant (business_id, id),
  UNIQUE KEY uq_wa_phone (phone_number_id),
  KEY ix_wa_business (business_id, token_state),
  CONSTRAINT fk_wa_business FOREIGN KEY (business_id) REFERENCES businesses (id),
  CONSTRAINT chk_wa_token_state CHECK (token_state IN
    ('stored', 'verified', 'expired', 'revoked', 'error', 'cleared')),
  CONSTRAINT chk_wa_webhook_state CHECK (webhook_state IN
    ('not_subscribed', 'subscribed', 'subscribe_failed')),
  CONSTRAINT chk_wa_registration_state CHECK (registration_state IN
    ('unknown', 'not_registered', 'registered', 'failed')),
  CONSTRAINT chk_wa_messaging_state CHECK (messaging_state IN
    ('unverified', 'send_verified', 'receive_verified', 'send_and_receive_verified')),
  CONSTRAINT chk_wa_kind CHECK (token_kind IN
    ('unknown', 'system_user', 'user_long_lived', 'user_short_lived')),
  CONSTRAINT chk_wa_refresh CHECK (refresh_supported IN (0, 1)),
  CONSTRAINT chk_wa_token_parts CHECK (
    (
      token_state IN ('stored', 'verified')
      AND token_ciphertext IS NOT NULL
      AND token_nonce IS NOT NULL
      AND token_tag IS NOT NULL
      AND token_key_version IS NOT NULL
    )
    OR
    (
      token_state IN ('expired', 'revoked', 'error', 'cleared')
      AND token_ciphertext IS NULL
      AND token_nonce IS NULL
      AND token_tag IS NULL
      AND token_key_version IS NULL
    )
  ),
  CONSTRAINT chk_wa_messaging_token CHECK (
    messaging_state = 'unverified' OR token_state = 'verified'
  ),
  CONSTRAINT chk_wa_receive_webhook CHECK (
    messaging_state NOT IN ('receive_verified', 'send_and_receive_verified')
    OR webhook_state = 'subscribed'
  ),
  CONSTRAINT chk_wa_full_messaging CHECK (
    messaging_state <> 'send_and_receive_verified'
    OR (
      display_phone IS NOT NULL
      AND subscribed_at IS NOT NULL
      AND registration_state = 'registered'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE onboarding_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  correlation_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  session_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  finish_event VARCHAR(64) NULL,
  current_step VARCHAR(64) NULL,
  meta_error_code VARCHAR(64) NULL,
  reported_waba_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  reported_phone_number_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  token_ciphertext VARBINARY(2048) NULL,
  token_nonce BINARY(12) NULL,
  token_tag BINARY(16) NULL,
  token_key_version SMALLINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_onboarding_correlation (correlation_hash),
  KEY ix_onboarding_open (business_id, status, expires_at),
  CONSTRAINT fk_onboarding_membership
    FOREIGN KEY (business_id, user_id)
    REFERENCES business_users (business_id, user_id),
  CONSTRAINT chk_onboarding_status CHECK (status IN (
    'pending', 'exchanging', 'token_held', 'completed',
    'abandoned', 'rejected', 'incomplete', 'expired'
  )),
  CONSTRAINT chk_onboarding_token_parts CHECK (
    (
      token_ciphertext IS NULL
      AND token_nonce IS NULL
      AND token_tag IS NULL
      AND token_key_version IS NULL
    )
    OR
    (
      token_ciphertext IS NOT NULL
      AND token_nonce IS NOT NULL
      AND token_tag IS NOT NULL
      AND token_key_version IS NOT NULL
    )
  ),
  CONSTRAINT chk_onboarding_token_held CHECK (
    status <> 'token_held'
    OR (
      token_ciphertext IS NOT NULL
      AND token_nonce IS NOT NULL
      AND token_tag IS NOT NULL
      AND token_key_version IS NOT NULL
    )
  ),
  CONSTRAINT chk_onboarding_terminal_token CHECK (
    status NOT IN ('completed', 'abandoned', 'rejected', 'incomplete', 'expired')
    OR token_ciphertext IS NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retensi payload: cipher, nonce, tag dan versi kunci boleh ada semasa
-- accepted, processing atau failed supaya percubaan semula boleh membaca payload.
-- Status processed mewajibkan keempat-empatnya kosong dan payload_purged_at terisi.
-- payload_hash kekal. Kandungan mesej tidak disimpan selepas processed.
CREATE TABLE webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NULL,
  connection_id BIGINT UNSIGNED NULL,
  dedupe_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  event_kind VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL,
  result_code VARCHAR(32) NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  locked_until DATETIME NULL,
  next_retry_at DATETIME NULL,
  last_error VARCHAR(191) NULL,
  payload_hash CHAR(64) NOT NULL,
  payload_ciphertext MEDIUMBLOB NULL,
  payload_nonce BINARY(12) NULL,
  payload_tag BINARY(16) NULL,
  payload_key_version SMALLINT UNSIGNED NULL,
  payload_purged_at DATETIME NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_dedupe (dedupe_key),
  KEY ix_webhook_retry (status, next_retry_at, id),
  KEY ix_webhook_lock (status, locked_until, id),
  CONSTRAINT fk_webhook_connection
    FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id),
  CONSTRAINT chk_webhook_status CHECK (status IN ('accepted', 'processing', 'processed', 'failed')),
  CONSTRAINT chk_webhook_kind CHECK (event_kind IN ('message', 'status', 'other', 'account_update')),
  CONSTRAINT chk_webhook_pair CHECK (
    (business_id IS NULL AND connection_id IS NULL)
    OR (business_id IS NOT NULL AND connection_id IS NOT NULL)
  ),
  CONSTRAINT chk_webhook_attempts CHECK (
    attempt_count >= 0 AND attempt_count <= max_attempts
  ),
  CONSTRAINT chk_webhook_result CHECK (
    status <> 'processed' OR result_code IS NOT NULL
  ),
  CONSTRAINT chk_webhook_result_value CHECK (
    result_code IS NULL OR result_code IN (
      'no_reply', 'reply_sent', 'reply_unknown', 'unknown_phone', 'invalid_payload'
    )
  ),
  CONSTRAINT chk_webhook_payload_parts CHECK (
    (
      payload_ciphertext IS NULL
      AND payload_nonce IS NULL
      AND payload_tag IS NULL
      AND payload_key_version IS NULL
    )
    OR
    (
      payload_ciphertext IS NOT NULL
      AND payload_nonce IS NOT NULL
      AND payload_tag IS NOT NULL
      AND payload_key_version IS NOT NULL
    )
  ),
  CONSTRAINT chk_webhook_purged CHECK (
    status <> 'processed'
    OR (
      payload_ciphertext IS NULL
      AND payload_nonce IS NULL
      AND payload_tag IS NULL
      AND payload_key_version IS NULL
      AND payload_purged_at IS NOT NULL
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  actor_kind VARCHAR(16) NOT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_business (business_id, id),
  KEY ix_audit_entity (business_id, entity_type, entity_id),
  CONSTRAINT fk_audit_business FOREIGN KEY (business_id) REFERENCES businesses (id),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_audit_kind CHECK (actor_kind IN ('user', 'system')),
  CONSTRAINT chk_audit_actor CHECK (
    (actor_kind = 'user' AND actor_user_id IS NOT NULL)
    OR (actor_kind = 'system' AND actor_user_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
