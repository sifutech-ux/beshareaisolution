CREATE TABLE IF NOT EXISTS ai_followups (
  event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  consent_at DATETIME NOT NULL,
  due_at DATETIME NOT NULL,
  status VARCHAR(12) NOT NULL,
  KEY ix_followup_due (business_id,status,due_at),
  KEY ix_followup_thread (business_id,conversation_id,status),
  FOREIGN KEY (event_id) REFERENCES ai_reply_jobs(event_id),
  FOREIGN KEY (business_id,connection_id) REFERENCES whatsapp_connections(business_id,id),
  FOREIGN KEY (business_id,conversation_id) REFERENCES ai_conversations(business_id,id),
  CHECK (status IN ('scheduled','cancelled','sent','failed','unknown','expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
