import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const script = readFileSync(new URL('../penasihat/penasihat.js', import.meta.url), 'utf8')
const audioFunction = script.slice(script.indexOf('async function mainSuara('), script.indexOf('\nfunction failCakap('))
const sessionFunction = script.slice(script.indexOf('function sesiAkaun('), script.indexOf('\nfunction kepalaAkaun('))

function setup(response, session = { token: 'synthetic-token', email: 'qa@example.invalid' }) {
  const effects = { requests: [], decoded: 0, played: 0, resumed: 0 }
  const audio = { state:'suspended', resume:async()=>{ effects.resumed++ }, decodeAudioData:async(buffer)=>{ effects.decoded=buffer.byteLength; return {} }, createBufferSource:()=>({ connect(){}, start(){ effects.played++ } }), destination:{} }
  const context = vm.createContext({
    AKAUN_KUNCI:'beshare-akaun', localStorage:{getItem:()=>JSON.stringify(session)},
    status:{textContent:''}, SUARA_API:'https://nasihat.beshareaisolution.com/api/penasihat-suara',
    SUARA_GAGAL:'Suara gagal.', sumber:null, bukaAudio:()=>audio,
    ayatPelayar:(_error,fallback)=>fallback, atob, Uint8Array,
    fetch:async(url,options)=>{ effects.requests.push({url,options}); return response },
  })
  vm.runInContext(sessionFunction + '\n' + audioFunction,context)
  return { context,effects }
}

test('Dengar sends session auth, decodes returned audio, resumes playback and resets button', async()=>{
  const {context,effects}=setup({ok:true,json:async()=>({audio:Buffer.from('synthetic-audio').toString('base64'),mime:'audio/wav'})})
  const button={disabled:false}
  await context.mainSuara({teks:'Contoh jawapan.',langkah:['Satu','Dua','Tiga']},button)
  assert.equal(effects.requests.length,1)
  assert.equal(effects.requests[0].options.headers.Authorization,'Bearer synthetic-token')
  assert.equal(JSON.parse(effects.requests[0].options.body).jawapan,'Contoh jawapan.')
  assert.equal(effects.decoded,15)
  assert.equal(effects.resumed,1)
  assert.equal(effects.played,1)
  assert.equal(button.disabled,false)
  assert.equal(context.status.textContent,'Suara AI sedang dimainkan.')
  context.sumber.onended()
  assert.equal(context.status.textContent,'')
})

test('signed-out and failed audio never play or leave the control disabled',async()=>{
  const signedOut=setup({},null)
  await signedOut.context.mainSuara({teks:'Jawapan.'},{disabled:false})
  assert.equal(signedOut.effects.requests.length,0)
  for (const response of [{ok:false,json:async()=>({error:'Tidak tersedia.'})},{ok:true,json:async()=>({})}]) {
    const {context,effects}=setup(response); const button={disabled:false}
    await context.mainSuara({teks:'Jawapan.'},button)
    assert.equal(button.disabled,false)
    assert.equal(effects.played,0)
    assert.equal(context.status.textContent,'Suara gagal.')
  }
})
