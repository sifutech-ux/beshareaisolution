"""Account isolation, lost-update and retry behavior for saved work/reviews."""
import concurrent.futures
import json
import os
import unittest
import test_pelanggan_kuota as kuota_tests
from test_pelanggan_kuota import KuotaCases, STAMP

BASE = '/api/akaun/copilot/hasil'
WEEK = '2023-11-13'
DRAFT = {'versi':0,'jenis':'pemasaran','tajuk':'Promosi','saluran':'WhatsApp','status':'draf','kandungan':'Latte RM8. Bukan rekod jualan.'}
REVIEW = {'versi':0,'dibuat':'Hubungi lima pelanggan','hasil':'Satu pertanyaan','halangan':'Belum ada harga penghantaran','tugasan_id':''}

class HasilCases:
    for name in ('setUp','_restore_secret','connect','dispatch_raw','q','profil'):
        locals()[name] = getattr(KuotaCases, name)

    def call(self, method, suffix, data=None, token=''):
        return self.dispatch_raw(method, BASE+suffix, json.dumps(data if data is not None else {}).encode(), token)

    def test_auth_owner_isolation_and_foreign_identifier(self):
        token, _ = self.profil('a@example.com'); other, _ = self.profil('b@example.com')
        key = 'a'*32
        self.assertEqual(self.call('GET','/draf')[0],401)
        self.assertEqual(self.call('PUT','/draf/'+key,DRAFT)[0],401)
        self.assertEqual(self.call('PUT','/draf/'+key,DRAFT,token)[0],200)
        self.assertEqual(self.call('GET','/draf',token=other)[1]['draf'],[])
        self.assertEqual(self.call('GET','/draf/'+key,token=other)[0],404)
        status, data = self.call('PUT','/draf/'+key,DRAFT,other)
        self.assertEqual(status,409); self.assertNotIn('draf',data)
        self.assertEqual(self.call('PUT','/semakan/'+WEEK,REVIEW,token)[0],200)
        self.assertEqual(self.call('GET','/semakan',token=other)[1]['semakan'],[])
        context = self.dispatch_raw('GET','/api/akaun/copilot/konteks',b'',other)[1]
        self.assertEqual(context['semakan_mingguan'],[])

    def test_persistence_replay_conflict_archive_restore(self):
        token, _ = self.profil('a@example.com'); path='/draf/'+'a'*32
        self.assertEqual(self.call('PUT',path,DRAFT,token)[1]['draf']['versi'],1)
        self.assertTrue(self.call('PUT',path,DRAFT,token)[1]['main_semula'])
        update={**DRAFT,'versi':1,'kandungan':'Harga RM9 selepas semakan','status':'digunakan'}
        self.assertEqual(self.call('PUT',path,update,token)[1]['draf']['versi'],2)
        code, conflict = self.call('PUT',path,{**DRAFT,'versi':1,'tajuk':'Versi lama'},token)
        self.assertEqual(code,409); self.assertEqual(conflict['draf']['kandungan'],update['kandungan'])
        self.assertTrue(self.call('PUT',path,update,token)[1]['main_semula'])
        for version, state in [(2,'arkib'),(3,'draf')]:
            self.assertEqual(self.call('PUT',path,{**update,'versi':version,'status':state},token)[0],200)
        item=self.call('GET',path,token=token)[1]['draf']
        self.assertEqual((item['versi'],item['status']),(4,'draf'))
        self.assertEqual(self.q('SELECT COUNT(*) FROM copilot_work')[0][0],1)

    def test_strict_payload_and_content_boundaries(self):
        token,_=self.profil('a@example.com'); path='/draf/'+'b'*32
        for field,value in [('versi',True),('versi',-1),('jenis',[]),('status',{}),('saluran',1),('tajuk',''),('kandungan','a'*8001),('kandungan','\x00')]:
            with self.subTest(field=field,value=str(value)[:10]):
                self.assertEqual(self.call('PUT',path,{**DRAFT,field:value},token)[0],400)
        self.assertEqual(self.call('PUT',path,{**DRAFT,'akaun_id':'foreign'},token)[0],400)
        self.assertEqual(self.call('PUT','/draf/not-an-id',DRAFT,token)[0],404)
        self.assertEqual(self.call('PUT',path,{**DRAFT,'kandungan':'a'*8000},token)[0],200)
        self.assertEqual(len(self.call('GET','/draf',token=token)[1]['draf'][0]['ringkasan']),160)

    def test_capacity_includes_archive(self):
        token,_=self.profil('a@example.com')
        # Seed capacity directly; exercise enforcement through the authenticated dispatcher.
        owner=self.q('SELECT id FROM pelanggan_akaun')[0][0]
        conn=self.connect(); ph='%s' if self.postgres else '?'
        rows=[(format(i,'032x'),owner,'pemasaran','Draf','Lain','arkib','Isi',1,'2023','2023') for i in range(200)]
        conn.cursor().executemany('INSERT INTO copilot_work VALUES ('+','.join([ph]*10)+')',rows); conn.commit(); conn.close()
        self.assertEqual(self.call('PUT','/draf/'+'f'*32,DRAFT,token)[0],409)
        self.assertEqual(self.call('PUT','/draf/'+format(1,'032x'),{**DRAFT,'versi':1},token)[0],200)

    def test_review_context_no_automatic_numbers_or_tasks(self):
        token,_=self.profil('a@example.com')
        path='/semakan/'+WEEK
        self.assertEqual(self.call('PUT',path,REVIEW,token)[1]['semakan']['versi'],1)
        self.assertTrue(self.call('PUT',path,REVIEW,token)[1]['main_semula'])
        self.assertEqual(self.call('PUT',path,{**REVIEW,'hasil':'Versi lain'},token)[0],409)
        self.assertEqual(self.call('PUT',path,{**REVIEW,'versi':1,'hasil':'Jualan RM100 menurut catatan'},token)[0],200)
        context=self.dispatch_raw('GET','/api/akaun/copilot/konteks',b'',token)[1]
        self.assertEqual(context['semakan_mingguan'][0]['versi'],2)
        self.assertIsNone(context['angka']['minggu']['hasil'])
        self.assertEqual(self.q('SELECT COUNT(*) FROM pelanggan_tugas')[0][0],0)

    def test_review_week_and_owned_task(self):
        token,_=self.profil('a@example.com'); other,_=self.profil('b@example.com')
        for week in ('2099-01-05','2023-11-14','2023-01-02','junk','2023-02-30'):
            self.assertEqual(self.call('PUT','/semakan/'+week,REVIEW,token)[0],400)
        taskbody={'minggu_mula':WEEK,'teks':'Terbitkan promosi','versi':0}
        code,result=self.dispatch_raw('POST','/api/akaun/pelan/tugasan',json.dumps(taskbody).encode(),token)
        self.assertEqual(code,200,result)
        task=result['pelan']['tugasan'][0]['id']
        self.assertEqual(self.call('PUT','/semakan/'+WEEK,{**REVIEW,'tugasan_id':task},other)[0],400)
        self.assertEqual(self.call('PUT','/semakan/2023-11-06',{**REVIEW,'tugasan_id':task},token)[0],400)
        self.assertEqual(self.call('PUT','/semakan/'+WEEK,{**REVIEW,'tugasan_id':task},token)[0],200)

    def test_two_writers_only_one_update_and_same_retry(self):
        token,_=self.profil('a@example.com'); path='/draf/'+'c'*32
        self.call('PUT',path,DRAFT,token)
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            codes=list(pool.map(lambda title: self.call('PUT',path,{**DRAFT,'versi':1,'tajuk':title},token)[0],['Peranti A','Peranti B']))
        self.assertEqual(sorted(codes),[200,409])
        with concurrent.futures.ThreadPoolExecutor(2) as pool:
            codes=list(pool.map(lambda _: self.call('PUT','/semakan/'+WEEK,REVIEW,token)[0],range(2)))
        self.assertEqual(codes,[200,200]); self.assertEqual(self.call('GET','/semakan',token=token)[1]['semakan'][0]['versi'],1)

class SqliteHasilTests(HasilCases,unittest.TestCase):
    postgres=False

@unittest.skipUnless(os.getenv('COPILOT_TEST_POSTGRES')=='1','PostgreSQL fixture not enabled')
class PostgresHasilTests(HasilCases,unittest.TestCase):
    postgres=True
    connect=kuota_tests.PostgresKuotaTests.connect
    def setUp(self):
        HasilCases.setUp(self)
        conn=self.connect();cur=conn.cursor()
        cur.execute("SELECT tablename FROM pg_tables WHERE schemaname='public'")
        for (name,) in cur.fetchall():
            if name.startswith(('pelanggan_','copilot_')): cur.execute('DROP TABLE "'+name+'" CASCADE')
        conn.commit();conn.close()
