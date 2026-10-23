import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import assert from 'node:assert/strict';
const source = readFileSync(new URL('../../whatsapp/ruang.js', import.meta.url), 'utf8');
const launch = source.slice(source.indexOf('function beginSignup('), source.indexOf('function exchangeSignupCode('));
let checks = 0;
function check(name, value) { assert.ok(value, name); console.log('OK ' + name); checks++; }
function fixture({ user = 'owner', sdk = true, behavior = 'silent', accepted = true } = {}) {
  const calls = [], notes = [], timers = new Map(), hint = { textContent: '' };
  let record = null, callback, timerId = 0;
  const context = createContext({
    load: () => user ? {} : null, role: () => user,
    signupReady: { appId: '123456', configId: '654321' }, signupSdkReady: sdk,
    facebookSdkReady: () => sdk, signupQueue: null, signupState: '', signupLaunchTimer: 0,
    document: { getElementById: () => hint, visibilityState: 'visible' },
    window: { clearTimeout: id => timers.delete(id), setTimeout: fn => { timers.set(++timerId, fn); return timerId; },
      FB: { login: (cb, options) => { calls.push(['login', options]); callback = cb;
        if (behavior === 'throw') throw new Error('secret must not appear');
        if (behavior === 'cancel') cb({ status: 'unknown' });
        if (behavior === 'code') cb({ authResponse: { code: 'synthetic-code' } });
      } } },
    showSignupNote: note => notes.push(note), signupFailure: note => notes.push(note),
    writeSignupRecord: value => { record = value; }, readSignupRecord: () => record,
    clearSignupRecord: () => { record = null; }, signupCode: value => value || '', metaStatus: r => r.status || '',
    rememberSignupCode: code => { record.code = code; }, flushSignup: () => {},
    rememberSignupState: state => { record.state = state; },
    callApi: async action => { calls.push([action]); return accepted ? { ok: true, state: 'a'.repeat(64) } : { ok: false, error: 'Sesi tamat' }; }
  });
  runInContext(launch, context);
  return { context, calls, notes, hint, timers, record: () => record, callback: r => callback(r), tick: () => { for (const fn of [...timers.values()]) fn(); } };
}
for (const user of [null, 'staff', 'admin']) {
  const f = fixture({ user }); f.context.beginSignup({});
  check(`${user || 'anonymous'} cannot launch or create signup session`, f.calls.length === 0);
}
{
  const f = fixture({ sdk: false }); f.context.beginSignup({});
  check('unready SDK makes no request', f.calls.length === 0);
}
{
  const f = fixture({ behavior: 'throw' }); f.context.beginSignup({});
  check('SDK throw does not create server session', f.calls.length === 1 && f.calls[0][0] === 'login');
  check('SDK throw clears pending state and timer', f.context.signupQueue === null && f.record() === null && f.timers.size === 0);
  check('SDK exception details are not exposed', f.notes.length === 1 && !f.notes[0].includes('secret'));
}
{
  const f = fixture({ behavior: 'cancel' }); f.context.beginSignup({});
  check('synchronous cancel does not create session', f.calls.length === 1);
  check('cancel feedback is preserved', f.notes.some(n => n.includes('ditutup')) && !f.hint.textContent.includes('Tetingkap Meta dibuka'));
}
{
  const f = fixture(); const options = { extras: { featureType: 'whatsapp_business_app_onboarding' } };
  f.context.beginSignup(options); await Promise.resolve();
  check('login stays in click activation before API request', f.calls.map(c => c[0]).join(',') === 'login,sambung');
  check('coexistence options reach SDK unchanged', f.calls[0][1] === options);
  check('silent SDK does not claim popup opened', !f.hint.textContent.includes('Tetingkap Meta dibuka'));
  f.tick(); check('silent SDK has actionable bounded feedback', f.notes.some(n => n.includes('pop-up') && n.includes('belum disahkan')));
  f.callback({ authResponse: { code: 'synthetic-late-code' } });
  check('late callback remains usable after advisory', f.record().code === 'synthetic-late-code' && f.timers.size === 0);
}
{
  const f = fixture({ behavior: 'code' }); f.context.beginSignup({}); await Promise.resolve(); f.tick();
  check('immediate authorization code survives server-state race', f.record().code === 'synthetic-code' && f.record().state === 'a'.repeat(64));
  check('successful callback suppresses popup advisory', f.notes.length === 0 && f.timers.size === 0);
}
{
  const f = fixture({ accepted: false }); f.context.beginSignup({}); await Promise.resolve(); f.tick();
  check('server denial clears pending attempt', f.context.signupQueue === null && f.record() === null && f.timers.size === 0);
  check('server denial remains visible', f.notes.join() === 'Sesi tamat');
}
{
  const f = fixture(); f.context.beginSignup({}); await Promise.resolve(); f.record().hidden = true; f.tick();
  check('focus transfer does not produce popup-blocked advisory', f.notes.length === 0);
}
console.log(`PASS ${checks} launch checks; synthetic SDK and API, no network`);
