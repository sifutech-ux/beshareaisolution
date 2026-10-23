-- Additive owner pilot. Existing foundation and Coexistence tables remain intact.
-- Install with prepare-ai-replies.php; do not re-run the foundation migration.
CREATE TABLE ai_reply_settings (
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
  knowledge_ciphertext MEDIUMBLOB NULL,
  knowledge_nonce BINARY(12) NULL,
  knowledge_tag BINARY(16) NULL,
  knowledge_key_version SMALLINT UNSIGNED NULL,
  knowledge_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  knowledge_hash CHAR(64) NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  allowlist_json TEXT NOT NULL,
  daily_limit SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  quota_date DATE NULL,
  quota_used INT UNSIGNED NOT NULL DEFAULT 0,
  activation_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (business_id),
  CONSTRAINT fk_ai_settings_connection FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_settings_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_ai_settings_enabled CHECK (enabled IN (0,1)),
  CONSTRAINT chk_ai_settings_limit CHECK (daily_limit BETWEEN 1 AND 1000),
  CONSTRAINT chk_ai_settings_knowledge CHECK (
    (knowledge_ciphertext IS NULL AND knowledge_nonce IS NULL AND knowledge_tag IS NULL AND knowledge_key_version IS NULL
     AND approved_by IS NULL AND approved_at IS NULL AND knowledge_hash IS NULL)
    OR (knowledge_ciphertext IS NOT NULL AND knowledge_nonce IS NOT NULL AND knowledge_tag IS NOT NULL
        AND knowledge_key_version IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND knowledge_hash IS NOT NULL)
  ),
  CONSTRAINT chk_ai_settings_active CHECK (enabled = 0 OR (approved_at IS NOT NULL AND activation_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  recipient VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mode VARCHAR(16) NOT NULL DEFAULT 'human',
  last_inbound_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_conversation_peer (business_id, connection_id, recipient),
  UNIQUE KEY uq_ai_conversation_tenant (business_id, id),
  KEY ix_ai_conversation_recent (business_id, updated_at),
  CONSTRAINT fk_ai_conversation_connection FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_ai_conversation_mode CHECK (mode IN ('ai','human'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  source_event_id BIGINT UNSIGNED NULL,
  direction VARCHAR(3) NOT NULL,
  wamid VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  body_ciphertext MEDIUMBLOB NOT NULL,
  body_nonce BINARY(12) NOT NULL,
  body_tag BINARY(16) NOT NULL,
  body_key_version SMALLINT UNSIGNED NOT NULL,
  message_at DATETIME NOT NULL,
  status VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_message_wamid (connection_id, wamid),
  UNIQUE KEY uq_ai_message_event_direction (business_id, source_event_id, direction),
  UNIQUE KEY uq_ai_message_tenant (business_id, id),
  KEY ix_ai_message_conversation (business_id, conversation_id, id),
  CONSTRAINT fk_ai_message_connection FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_message_conversation FOREIGN KEY (business_id, conversation_id)
    REFERENCES ai_conversations (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_message_event FOREIGN KEY (source_event_id) REFERENCES webhook_events (id) ON DELETE RESTRICT,
  CONSTRAINT chk_ai_message_direction CHECK (direction IN ('in','out')),
  CONSTRAINT chk_ai_message_status CHECK (status IN ('received','generated','sending','sent','delivered','read','failed','unknown','handoff'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_reply_jobs (
  event_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  knowledge_revision BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  reply_message_id BIGINT UNSIGNED NULL,
  generation_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_error VARCHAR(64) NULL,
  claimed_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (event_id),
  KEY ix_ai_job_recovery (business_id, status, locked_until),
  CONSTRAINT fk_ai_job_event FOREIGN KEY (event_id) REFERENCES webhook_events (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_job_connection FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_job_conversation FOREIGN KEY (business_id, conversation_id)
    REFERENCES ai_conversations (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_ai_job_reply FOREIGN KEY (business_id, reply_message_id)
    REFERENCES ai_messages (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_ai_job_status CHECK (status IN ('generating','generated','sending','sent','handoff','unknown','skipped','failed')),
  CONSTRAINT chk_ai_job_attempts CHECK (generation_attempts <= 1 AND send_attempts <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
