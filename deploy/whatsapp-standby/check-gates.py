#!/usr/bin/env python3
"""Read-only readiness report. Never starts a service or accepts approval from env."""
import json,pathlib,sys
p=pathlib.Path(__file__).with_name('gates.json')
g=json.loads(p.read_text())
required={'security_review','ledger_review','target_architecture','runtime_digest','host_capacity_listeners','least_privilege','backup_custody','restore_drill','preservation_inventory','owner_start_approval'}
missing=sorted(k for k in required if g.get(k,{}).get('status')!='verified' or not g[k].get('evidence'))
print(json.dumps({'start_allowed':not missing,'blocked':missing,'outbound':False},indent=2))
sys.exit(1 if missing else 0)
