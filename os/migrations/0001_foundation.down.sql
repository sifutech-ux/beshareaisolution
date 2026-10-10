-- Pemulihan manual sahaja.
-- Runner tidak memuatkan fail ini, tidak melaksanakan DROP, dan tidak
-- menyisipkan versi sebagai pemulihan.
-- Nama objek tidak mencukupi. Jalankan hanya selepas definisi sebenar
-- jadual, foreign key, CHECK, kolum janaan, indeks dan trigger sepadan
-- dengan 0001_foundation.up.sql, dan checksum SHA-256 fail migrasi itu
-- sama dengan checksum yang diluluskan.
-- Inventori dibandingkan dengan preflight. Setiap jadual baharu mempunyai
-- sifar baris. Objek asing, definisi salah, atau checksum berbeza
-- menghentikan pemulihan.
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
