import { readFileSync } from 'node:fs';
import { Script, createContext } from 'node:vm';
import assert from 'node:assert/strict';
const source = readFileSync(new URL('../../whatsapp/ruang.js',import.meta.url),'utf8');
let checks=0;
const check=(label,value)=>{assert.ok(value,label);checks++;};
function fixture(initialHash){
  const calls=[],writes=[],nodes=new Map(); let hash=initialHash; let reply={ok:true,message:'Synthetic recovery notice'};
  const location={pathname:'/whatsapp/ruang.html',search:'',origin:'https://fixture.invalid',get hash(){return hash;},set hash(v){hash=v.startsWith('#')?v:'#'+v;}};
  const node=id=>{if(!nodes.has(id))nodes.set(id,{innerHTML:'',hidden:true,addEventListener(){}});return nodes.get(id);};
  const button={disabled:false,innerHTML:'submit',textContent:''};
  class Form{constructor(kind,values){this.kind=kind;this.values=values;this.dataset={};nodes.set('auth',this);}getAttribute(key){return key==='id'?'auth':this.kind;}querySelector(){return button;}}
  class Data{constructor(form){this.values=form.values;}get(key){return this.values[key]||'';}}
  const context=createContext({
    URL,URLSearchParams,HTMLFormElement:Form,FormData:Data,location,
    history:{replaceState(_,__,url){hash=url.slice(url.indexOf('#'));}},
    sessionStorage:{getItem(){return null;},setItem(k,v){writes.push([k,v]);},removeItem(){}},
    window:{addEventListener(){},setTimeout(){},clearTimeout(){}},requestAnimationFrame:fn=>fn(),
    document:{documentElement:{dataset:{}},getElementById:node,querySelector(){return null;},addEventListener(){},visibilityState:'visible'},
    fetch:async(url,init={})=>{calls.push({url,init,hash});return {ok:true,json:async()=>url.endsWith('=sesi')?{ok:true,csrf:'synthetic',user:null}:reply};},
  });
  new Script(source).runInContext(context);
  return {calls,writes,node,button,context,hash:()=>hash,reply:r=>{reply=r;},
    submit:(kind,values)=>{new Script('onSubmit(testEvent)').runInContext(Object.assign(context,{testEvent:{target:new Form(kind,values),preventDefault(){}}}));},
    settle:async()=>{for(let i=0;i<3;i++)await new Promise(resolve=>setImmediate(resolve));},
    read:code=>new Script(code).runInContext(context)};
}
const token='a'.repeat(64);
const f=fixture('#pulih='+token);await f.settle();
check('fragment stripped before first network request',f.calls[0].hash==='#pulih');
check('secret kept out of URL and session storage',!f.hash().includes(token)&&!JSON.stringify(f.writes).includes(token));
check('reset page is public',f.node('app').innerHTML.includes('Kata laluan baharu.'));
check('no automatic token consumption',f.calls.length===1&&f.calls[0].url.endsWith('=sesi'));
check('secret is not rendered into markup',!f.node('app').innerHTML.includes(token));
f.submit('pulih',{password:'synthetic-new-password',password2:'different'});await f.settle();
check('mismatch prevents submission',f.calls.length===1&&f.node('form-error').textContent.includes('tidak sepadan'));
f.reply({ok:false,error:'Pautan tamat tempoh.'});f.submit('pulih',{password:'synthetic-new-password',password2:'synthetic-new-password'});await f.settle();
check('reset uses POST and CSRF',f.calls[1].init.method==='POST'&&f.calls[1].init.headers['X-CSRF-Token']==='synthetic');
check('token appears only in request body',JSON.parse(f.calls[1].init.body).token===token&&!f.calls[1].url.includes(token));
check('failure keeps form retryable',!f.button.disabled&&f.node('form-error').textContent==='Pautan tamat tempoh.');
f.reply({ok:true,user:null,message:'Kata laluan berjaya dikemas kini.'});f.submit('pulih',{password:'synthetic-new-password',password2:'synthetic-new-password'});await f.settle();
check('successful reset clears in-memory secret',f.read('recoveryToken')==='');
check('successful reset returns login link without automatic login',f.node('app').innerHTML.includes('Masuk semula')&&!f.calls.some(c=>c.url.endsWith('=masuk')));
const missing=fixture('#pulih');await missing.settle();check('refresh or missing token offers new link',missing.node('app').innerHTML.includes('Minta pautan baharu'));
const malformed=fixture('#pulih=bad%3Cscript');await malformed.settle();check('malformed fragment stripped and not rendered',malformed.hash()==='#pulih'&&!malformed.node('app').innerHTML.includes('bad%3Cscript'));
const forgot=fixture('#lupa');await forgot.settle();forgot.submit('lupa',{email:'owner@example.invalid'});await forgot.settle();
check('forgot form calls real request endpoint',forgot.calls[1].url.endsWith('=lupa')&&JSON.parse(forgot.calls[1].init.body).email==='owner@example.invalid');
check('request response shown as status',forgot.node('app').innerHTML.includes('Synthetic recovery notice'));
console.log(`PASS ${checks} recovery UI checks; real workspace script, no network`);
