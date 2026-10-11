-- PROPOSAL ONLY. Not approved. Not executed. Not part of Foundation 0001.
-- The migrator must not run this file. Inbox messages currently stay encrypted
-- inside webhook_events until a conversation store is approved.
-- Status updates must match graph_wamid plus the connection phone_number_id.
-- Do not match a delivery by recipient and time.

CREATE TABLE conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  customer_wa_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  mode VARCHAR(32) NOT NULL DEFAULT 'human_takeover',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversation_customer (connection_id, customer_wa_id),
  CONSTRAINT fk_conversation_connection
    FOREIGN KEY (business_id, connection_id)
    REFERENCES whatsapp_connections (business_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  graph_wamid VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  direction VARCHAR(16) NOT NULL,
  body_ciphertext MEDIUMBLOB NULL,
  body_nonce BINARY(12) NULL,
  body_tag BINARY(16) NULL,
  body_key_version SMALLINT UNSIGNED NULL,
  delivery_status VARCHAR(16) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_wamid (connection_id, graph_wamid),
  CONSTRAINT fk_message_conversation
    FOREIGN KEY (conversation_id) REFERENCES conversations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
