CREATE TABLE os_billing (
  business_id BIGINT UNSIGNED NOT NULL,
  mode VARCHAR(8) NOT NULL,
  customer VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  subscription VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'none',
  paid_until BIGINT NOT NULL DEFAULT 0,
  period_start BIGINT NOT NULL DEFAULT 0,
  setup_paid TINYINT NOT NULL DEFAULT 0,
  cancel_at_end TINYINT NOT NULL DEFAULT 0,
  attempt VARCHAR(64) NULL,
  attempt_at BIGINT NOT NULL DEFAULT 0,
  checkout VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  updated_at BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (business_id, mode),
  UNIQUE KEY uq_billing_customer (customer),
  UNIQUE KEY uq_billing_subscription (subscription),
  UNIQUE KEY uq_billing_checkout (checkout),
  CONSTRAINT fk_billing_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE os_billing_events (
  event_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  processed_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
