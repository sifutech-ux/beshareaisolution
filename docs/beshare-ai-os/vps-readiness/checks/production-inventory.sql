-- NOT EXECUTED on production. Run only through approved read-only credentials.
-- Select the intended schema in the protected client configuration, not a guessed DSN.
-- Stop if DATABASE() is not u879723783_beshare_os. Keep output private/redacted.
-- Metadata only: no customer contents, tokens, ciphertext, passwords or key bytes.
SELECT DATABASE() AS selected_schema, VERSION() AS server_version,
       @@version_comment AS distribution, @@sql_mode AS sql_mode,
       @@character_set_server AS server_charset, @@collation_server AS server_collation,
       @@session.time_zone AS session_timezone, UTC_TIMESTAMP() AS observed_utc;
SHOW VARIABLES WHERE Variable_name IN
 ('log_bin','binlog_format','binlog_expire_logs_seconds','expire_logs_days',
  'gtid_mode','gtid_strict_mode','sync_binlog','innodb_flush_log_at_trx_commit');
SELECT table_name, engine, table_collation, table_rows, data_length, index_length
FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name;
-- table_rows is an estimate, not restore-count evidence.
SELECT table_name, column_name, ordinal_position, column_type, is_nullable,
       column_default, extra, generation_expression, collation_name
FROM information_schema.columns WHERE table_schema=DATABASE()
ORDER BY table_name, ordinal_position;
SELECT table_name, constraint_name, constraint_type
FROM information_schema.table_constraints WHERE constraint_schema=DATABASE()
ORDER BY table_name, constraint_name;
SELECT table_name, column_name, constraint_name, referenced_table_name, referenced_column_name
FROM information_schema.key_column_usage WHERE table_schema=DATABASE()
ORDER BY table_name, constraint_name, ordinal_position;
SELECT trigger_name, event_manipulation, event_object_table, action_timing
FROM information_schema.triggers WHERE trigger_schema=DATABASE() ORDER BY trigger_name;
-- Obtain SHOW CREATE TABLE/TRIGGER privately for discovered objects afterward.
-- Missing privileges/unsupported fields => UNKNOWN; never infer absence from denial.
