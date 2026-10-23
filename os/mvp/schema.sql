-- Dedicated synthetic SQLite store. Not a production schema migration.
PRAGMA foreign_keys=ON;
CREATE TABLE tenants (id TEXT PRIMARY KEY, active INTEGER NOT NULL CHECK(active IN(0,1)), daily_calls INTEGER NOT NULL CHECK(daily_calls BETWEEN 0 AND 100), daily_units INTEGER NOT NULL CHECK(daily_units BETWEEN 0 AND 100000));
CREATE TABLE routes (waba TEXT NOT NULL, phone TEXT NOT NULL, tenant TEXT NOT NULL REFERENCES tenants(id), PRIMARY KEY(waba,phone), UNIQUE(phone));
CREATE TABLE operators (tenant TEXT NOT NULL REFERENCES tenants(id), actor TEXT NOT NULL, active INTEGER NOT NULL CHECK(active IN(0,1)), PRIMARY KEY(tenant,actor));
CREATE TABLE knowledge (tenant TEXT NOT NULL REFERENCES tenants(id), version INTEGER NOT NULL, body TEXT NOT NULL, approved_by TEXT, expires INTEGER NOT NULL, PRIMARY KEY(tenant,version), FOREIGN KEY(tenant,approved_by) REFERENCES operators(tenant,actor));
CREATE TABLE handoffs (tenant TEXT NOT NULL REFERENCES tenants(id), event TEXT NOT NULL, payload_hash TEXT NOT NULL, inbound TEXT NOT NULL, knowledge_version INTEGER, status TEXT NOT NULL CHECK(status IN('pending','draft','review','claimed')), reason TEXT NOT NULL, draft TEXT, actor TEXT, created INTEGER NOT NULL, PRIMARY KEY(tenant,event), FOREIGN KEY(tenant,knowledge_version) REFERENCES knowledge(tenant,version), FOREIGN KEY(tenant,actor) REFERENCES operators(tenant,actor));
CREATE TABLE budget (tenant TEXT NOT NULL REFERENCES tenants(id), day TEXT NOT NULL, calls INTEGER NOT NULL, units INTEGER NOT NULL, last_call INTEGER NOT NULL, PRIMARY KEY(tenant,day));
