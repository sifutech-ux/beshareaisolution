#!/usr/bin/env python3
"""Rerun only after ledger-lab.py; new restore volume name must be unused."""
import importlib.util,pathlib
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('lab',here.parent/'mysql84/lab.py')
lab=importlib.util.module_from_spec(spec);spec.loader.exec_module(lab)
lab.PREFIX='beshare-forward-20261023-apply';lab.EVID=here/'evidence'
lab.docker('start',lab.PREFIX+'-source',lab.PREFIX+'-app');lab.wait('source')
source=(here.parent/'mysql84/recovery.py').read_text().replace('from lab import *','').replace('sync()','',1)
try: exec(compile(source,'mysql84/recovery.py','exec'),lab.__dict__)
finally:
 for suffix in ['source','app','restore','recovery-app']:lab.docker('stop',lab.PREFIX+'-'+suffix,ok=False)
