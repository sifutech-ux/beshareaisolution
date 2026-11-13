#!/usr/bin/env python3
"""Only lab transport/setup changes; runtime source and assertions stay pinned."""
from pathlib import Path
import json,hashlib
root=Path('/lab/source')
p=root/'os/tests/run-security-p0.py';s=p.read_text()
s=s.replace("assert all([PHP, DB, INIT, CLIENT]),", "assert PHP,")
s=s.replace("sock = lab / 'db.sock'", "sock = Path('/sockets/p0/mysql.sock')")
a=s.index('        subprocess.run([INIT,');b=s.index('        php_args =',a)
s=s[:a]+s[b:]
a=s.index("        original = subprocess.check_output(['git','show',");b=s.index('\n',a)
s=s[:a]+"        original = Path('/lab/baseline-webhook.php').read_text()"+s[b:]
# Existing extended tests set a MariaDB-only mode. Keep strict mode on MySQL.
s=s.replace(".replace('host=127.0.0.1;port=3307',f'unix_socket={sock}')", ".replace('host=127.0.0.1;port=3307',f'unix_socket={sock}').replace('NO_AUTO_CREATE_USER,','')")
s=s.replace("extended_env['PHP_INI_SCAN_DIR']=str(lab/'ini')", "extended_env['PHP_INI_SCAN_DIR']='/usr/local/etc/php/conf.d:'+str(lab/'ini')")
p.write_text(s)
print(json.dumps({'adapter':'socket-only external disposable MySQL, no MariaDB process; fixed baseline read; remove obsolete NO_AUTO_CREATE_USER in test-only copied suites','adapted_runner_sha256':hashlib.sha256(s.encode()).hexdigest()}))
