#!/usr/bin/env python3
"""Validate retained evidence; never converts diagnostic success to standby approval."""
import json,pathlib,hashlib
p=pathlib.Path(__file__).resolve().parent;e=p/'evidence'
def get(n):return json.loads((e/(n+'.json')).read_text())
checks={
 'pinned_source':get('source-integrity')['all_match'],
 'mysql_8_4_13':get('version')['version']=='8.4.13',
 'diagnostic_ddl':get('migration-exit')['exit']==0,
 'p0_suites':get('p0-regression')['exit']==0,
 'signup_js':get('signup-js')['exit']==0,
 'restricted_source':get('restricted-source')['exit']==0,
 'restricted_restored':get('restricted-restored')['exit']==0,
 'persistence':get('persistence')['equal'],
 'restore':get('restore')['exit']==0 and get('restore-comparison')['canonical_equal'],
 'encrypted_recovery':get('encrypted-recovery')['checks_exit']==0 and get('encrypted-recovery')['canonical_fingerprint_equal'],
 'partial_refused':get('partial-injection')['exit']==9 and get('partial-rerun')['exit']==5,
 'stopped':get('shutdown')['all_stopped'],
}
for target in ['source','restore','partial','p0','app','recovery','recovery-app']:
 i=get('isolation-'+target);n=get('egress-'+target)
 checks['isolated_'+target]=i['network']=='none' and not i['ports'] and n['exit']!=0 and 'Network is unreachable' in n['stderr']
assert all(checks.values()),checks
assert get('foundation-gate')['exit']==4 and 'backup_server_unbound' in get('foundation-gate')['stderr']
assert '1054' in (e/'catalog-probe.txt').read_text()
summary={'diagnostic_checks':checks,'diagnostic_result':'PASS','foundation_and_standby':'BLOCKED','production_RPO_RTO':'NOT PROVEN','blockers':['BackupProof rejects genuine MySQL version','FoundationMigrator references missing cc.TABLE_NAME','Approved ledger/migration-role and target runtime/host/ownership/recovery gates remain open']}
(e/'summary.json').write_text(json.dumps(summary,indent=2)+'\n')
manifest={str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(p.rglob('*')) if f.is_file() and not any(x in f.parts for x in ['.local-backups','__pycache__']) and f.name!='evidence-sha256.json'}
(p/'evidence-sha256.json').write_text(json.dumps(manifest,indent=2)+'\n')
print('PASS diagnostic evidence; BLOCKED foundation initialization and standby')
