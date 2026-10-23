import lab,json
original=lab.PREFIX
original_evidence=lab.EVID
out=[]
for attempt,count,expected in [('partial',2,'partial'),('unrecorded',13,'ddl_complete_version_unrecorded')]:
 lab.PREFIX=original+'-'+attempt
 lab.EVID=original_evidence/attempt;lab.EVID.mkdir(exist_ok=True)
 lab.main(apply=False)
 code="require '/lab/source/os/src/SqlScript.php';$p=new PDO('mysql:unix_socket=/sockets/source/mysql.sock;dbname="+lab.DB+";charset=utf8mb4','migration','synthetic-unused');$p->exec(\"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci\");$s=BesharOs\\SqlScript::statements(file_get_contents('/lab/source/os/migrations/0001_foundation.up.sql'));foreach(array_slice($s,0,"+str(count)+") as $q)$p->exec($q);"
 lab.execapp('php','-r',code)
 r=lab.migrate()
 ledger=lab.sql('source',f'USE {lab.DB}; SELECT COUNT(*) FROM schema_migrations').stdout.decode().strip()
 out.append({'attempt':attempt,'statements_executed':count,'exit':r.returncode,'stdout':r.stdout.decode(),'stderr':r.stderr.decode(),'ledger_rows':ledger,'expected':expected})
 assert expected in r.stderr.decode() and ledger=='0'
 lab.docker('stop',lab.PREFIX+'-source',lab.PREFIX+'-app')
lab.PREFIX=original;lab.EVID=original_evidence;lab.save('interrupted.json',out);print('Partial and complete-unrecorded refusal PASS')
