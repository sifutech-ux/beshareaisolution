"""HTTP integration tests: own disposable state, no real provider calls."""
import http.cookiejar,json,os,pathlib,socket,subprocess,tempfile,time,urllib.request,urllib.error
root=pathlib.Path(__file__).resolve().parents[3]
with tempfile.TemporaryDirectory(prefix='beshare-demo-test-') as directory:
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env=dict(os.environ,BESHARE_DEMO_STATE=directory,BESHARE_DEMO_PORT=str(port),BESHARE_DEMO_MODE='mock')
    proc=subprocess.Popen(['php','os/demo/start.php'],cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    try:
        base=f'http://127.0.0.1:{port}';jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));checks=[]
        def req(path,data=None,csrf=None,origin=True,opener=client):
            headers={}
            if data is not None:headers={'Content-Type':'application/json','Origin':base if origin else 'https://evil.example','X-CSRF-Token':csrf or ''}
            r=urllib.request.Request(base+path,data=json.dumps(data).encode() if data is not None else None,headers=headers)
            try:
                with opener.open(r) as out:return out.status,out.read(),out.headers
            except urllib.error.HTTPError as e:return e.code,e.read(),e.headers
        def check(name,condition):
            assert condition,name
            checks.append(name)
        for _ in range(100):
            try:req('/');break
            except OSError:time.sleep(.05)
        check('unauthenticated denied',req('/api/state')[0]==401)
        check('private files unreachable',req('/../runtime.php')[0]==404 and req('/login.txt')[0]==404)
        password=pathlib.Path(directory,'login.txt').read_text().splitlines()[1]
        check('wrong password rejected',req('/api/login',{'password':'wrong'})[0]==401)
        check('foreign origin rejected',req('/api/login',{'password':password},origin=False)[0]==403)
        code,_,headers=req('/api/login',{'password':password})
        check('login and cookie protection',code==200 and 'HttpOnly' in headers.get('Set-Cookie','') and 'SameSite=Strict' in headers.get('Set-Cookie',''))
        state=json.loads(req('/api/state')[1]);csrf=state['csrf']
        check('explicit mock mode',state['mode']=='MOCK')
        check('CSRF required',req('/api/knowledge',{'body':'bad'})[0]==403)
        check('edit saved',req('/api/knowledge',{'body':'Synthetic price RM123 <script>alert(1)</script>'},csrf)[0]==200)
        check('unapproved yields human review',req('/api/simulate',{'message':'price?'},csrf)[0]==200 and json.loads(req('/api/state')[1])['items'][0]['reason']=='knowledge_denied')
        check('stale approval denied',req('/api/approve',{'version':1},csrf)[0]==409)
        check('latest approval succeeds',req('/api/approve',{'version':2},csrf)[0]==200)
        check('simulate generates',req('/api/simulate',{'message':'price?','tenant':'other'},csrf)[0]==200)
        state=json.loads(req('/api/state')[1]);draft=next(x for x in state['items'] if x['draft'])
        check('source revision and provenance',draft['knowledge_version']==2 and 'RM123' in draft['source'] and draft['mode']=='MOCK')
        check('budget reserved',state['calls']==1)
        req('/api/simulate',{'message':'too fast'},csrf)
        check('rate limit hands off',any(x['reason']=='budget_denied' for x in json.loads(req('/api/state')[1])['items']))
        check('claim survives reload',req('/api/claim',{'event':draft['event']},csrf)[0]==200 and any(x['status']=='claimed' for x in json.loads(req('/api/state')[1])['items']))
        check('unknown event denied',req('/api/claim',{'event':'other-tenant'},csrf)[0]==400)
        check('oversize rejected',req('/api/simulate',{'message':'x'*4001},csrf)[0]==400)
        check('API read cannot mutate',req('/api/approve')[0]==404)
        check('logout revokes session',req('/api/logout',{},csrf)[0]==200 and req('/api/state')[0]==401)
        print(json.dumps({'passed':len(checks),'checks':checks,'provider':'mock only'},indent=2))
    finally:
        import signal
        os.killpg(proc.pid,signal.SIGTERM);proc.wait()
