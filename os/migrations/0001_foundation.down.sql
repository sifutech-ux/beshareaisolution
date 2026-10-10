-- Pemulihan manual sahaja.
-- Runner tidak memuatkan atau menjalankan fail ini.
-- Jalankan hanya selepas inventori dibandingkan dengan preflight,
-- setiap objek baharu berada dalam senarai yang dibenarkan,
-- dan setiap jadual baharu mempunyai sifar baris.
-- Objek yang tidak dikenali atau jadual yang mempunyai data menghentikan pemulihan.
-- Jangan gunakan fail ini selepas baris schema_migrations wujud.

DROP TRIGGER IF EXISTS bd_business_users_owner;
DROP TRIGGER IF EXISTS bu_businesses_active_owner;
DROP TRIGGER IF EXISTS bu_business_users_owner;
DROP TRIGGER IF EXISTS bi_business_users_owner;
DROP TRIGGER IF EXISTS bi_businesses_active_owner;

DROP TABLE IF EXISTS webhook_events;
DROP TABLE IF EXISTS onboarding_sessions;
DROP TABLE IF EXISTS whatsapp_connections;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS business_users;
DROP TABLE IF EXISTS businesses;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS schema_migrations;
