-- BeShare AI OS 0002 — Coexistence sync storage.
-- Forward only. migrate.php does not apply this file.
-- Do not run it on production until a separate approval.

ALTER TABLE whatsapp_connections
  ADD COLUMN sync_contacts VARCHAR(24) NOT NULL DEFAULT 'not_applicable' AFTER messaging_state,
  ADD COLUMN sync_history VARCHAR(24) NOT NULL DEFAULT 'not_applicable' AFTER sync_contacts,
  ADD COLUMN onboarded_at DATETIME NULL AFTER updated_at,
  ADD COLUMN sync_deadline_at DATETIME NULL AFTER onboarded_at;

ALTER TABLE whatsapp_connections
  ADD CONSTRAINT chk_wa_sync_contacts CHECK (sync_contacts IN (
    'not_applicable', 'pending', 'in_progress', 'completed', 'declined', 'unavailable', 'failed'
  )),
  ADD CONSTRAINT chk_wa_sync_history CHECK (sync_history IN (
    'not_applicable', 'pending', 'in_progress', 'completed', 'declined', 'unavailable', 'failed'
  ));

ALTER TABLE webhook_events DROP CONSTRAINT chk_webhook_kind;

ALTER TABLE webhook_events
  ADD CONSTRAINT chk_webhook_kind CHECK (event_kind IN (
    'message', 'status', 'other', 'account_update', 'history', 'smb_app_state_sync', 'smb_message_echoes'
  ));

ALTER TABLE webhook_events DROP CONSTRAINT chk_webhook_result_value;

ALTER TABLE webhook_events
  ADD CONSTRAINT chk_webhook_result_value CHECK (
    result_code IS NULL OR result_code IN (
      'no_reply', 'reply_sent', 'reply_unknown', 'unknown_phone', 'invalid_payload', 'archived'
    )
  );

CREATE TABLE coexistence_sync_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  authorized_by BIGINT UNSIGNED NOT NULL,
  sync_type VARCHAR(32) NOT NULL,
  status VARCHAR(24) NOT NULL,
  request_id VARCHAR(128) NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  next_attempt_at DATETIME NULL,
  deadline_at DATETIME NOT NULL,
  last_error_code VARCHAR(64) NULL,
  authorized_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_connection_type (connection_id, sync_type),
  KEY ix_sync_due (status, next_attempt_at, id),
  CONSTRAINT fk_sync_connection
    FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id),
  CONSTRAINT fk_sync_author FOREIGN KEY (authorized_by) REFERENCES users (id),
  CONSTRAINT chk_sync_type CHECK (sync_type IN ('smb_app_state_sync', 'history')),
  CONSTRAINT chk_sync_status CHECK (status IN (
    'authorized', 'in_progress', 'completed', 'declined', 'failed', 'expired', 'cancelled'
  )),
  CONSTRAINT chk_sync_attempts CHECK (attempt_count >= 0 AND attempt_count <= max_attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coexistence_contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  phone_number VARCHAR(20) NOT NULL,
  full_name VARCHAR(120) NULL,
  first_name VARCHAR(80) NULL,
  sync_action VARCHAR(16) NOT NULL,
  changed_at VARCHAR(13) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coexistence_contact (connection_id, phone_number, sync_action, changed_at),
  KEY ix_coexistence_contact_business (business_id, id),
  CONSTRAINT fk_coexistence_contact
    FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id),
  CONSTRAINT chk_coexistence_contact_action CHECK (sync_action IN ('add', 'remove'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coexistence_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  source_kind VARCHAR(32) NOT NULL,
  wamid VARCHAR(180) NOT NULL,
  peer_phone VARCHAR(20) NULL,
  from_phone VARCHAR(20) NULL,
  to_phone VARCHAR(20) NULL,
  message_timestamp VARCHAR(13) NULL,
  message_type VARCHAR(32) NOT NULL,
  body_text VARCHAR(4000) NULL,
  media_id VARCHAR(64) NULL,
  media_sha256 CHAR(64) NULL,
  history_status VARCHAR(16) NULL,
  history_phase TINYINT UNSIGNED NULL,
  chunk_order INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_coexistence_message (connection_id, wamid),
  KEY ix_coexistence_message_business (business_id, message_timestamp, id),
  CONSTRAINT fk_coexistence_message
    FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id),
  CONSTRAINT chk_coexistence_message_source CHECK (source_kind IN ('history', 'echo', 'media'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
