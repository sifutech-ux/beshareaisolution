-- NOT EXECUTED on production. Stage 2 ONLY after Stage 1 confirms these columns.
-- SELECT-only; private output. Replace IDs with stable aliases before sharing in PR.
SELECT c.business_id, c.id AS connection_id, c.waba_id, c.phone_number_id,
       c.disconnected_at, c.token_state, c.webhook_state,
       c.registration_state, c.messaging_state, c.token_key_version,
       b.status AS business_status, b.owner_user_id,
       u.status AS owner_status, bu.role AS owner_membership
FROM whatsapp_connections c
LEFT JOIN businesses b ON b.id=c.business_id
LEFT JOIN users u ON u.id=b.owner_user_id
LEFT JOIN business_users bu ON bu.business_id=b.id AND bu.user_id=b.owner_user_id
ORDER BY c.business_id,c.id;
SELECT phone_number_id, COUNT(*) AS assignments
FROM whatsapp_connections GROUP BY phone_number_id HAVING COUNT(*)>1;
-- Shared WABA needs an explicitly reviewed ownership model; not automatically invalid.
SELECT waba_id, COUNT(DISTINCT business_id) AS tenants
FROM whatsapp_connections WHERE disconnected_at IS NULL
GROUP BY waba_id HAVING COUNT(DISTINCT business_id)>1;
SELECT b.id AS business_id, COUNT(bu.user_id) AS owner_memberships
FROM businesses b LEFT JOIN business_users bu ON bu.business_id=b.id AND bu.role='owner'
GROUP BY b.id HAVING COUNT(bu.user_id)<>1;
SELECT version,name,checksum,applied_at FROM schema_migrations ORDER BY version;
-- Ledger presence alone never certifies DDL. No token or payload columns selected.
