<?php
declare(strict_types=1);
require_once __DIR__.'/../mvp/GeminiFoundation.php';
use BesharOs\Mvp\GeminiFoundation;
const TENANT='demo-kedai';
const ACTOR='demo-owner';
function stateDir(): string { return getenv('BESHARE_DEMO_STATE') ?: sys_get_temp_dir().'/beshare-first-demo-'.posix_getuid(); }
function db(): PDO {
    $db=new PDO('sqlite:'.stateDir().'/demo.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    return $db;
}
function liveMode(): bool { return getenv('BESHARE_DEMO_MODE')==='live'; }
function transport(): Closure {
    return function(array $request): array {
        if (!liveMode()) {
            $data=json_decode($request['contents'][0]['parts'][0]['text'],true);
            return ['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>"[MOCK — contoh berasaskan maklumat diluluskan, bukan Gemini]\n\nTerima kasih menghubungi Kedai Seri Demo. Maklumat kami:\n".$data['approved_knowledge']."\n\nPasukan kami akan menyemak pertanyaan anda sebelum membalas."]]]]]];
        }
        $key=getenv('GEMINI_API_KEY');
        $model=getenv('BESHARE_GEMINI_MODEL') ?: 'gemini-2.5-flash';
        if (!$key || !preg_match('/\Agemini-[a-z0-9.-]+\z/',$model)) throw new RuntimeException('provider_configuration');
        if ($model==='gemini-2.5-flash') $request['generationConfig']['thinkingConfig']=['thinkingBudget'=>0,'includeThoughts'=>false];
        $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent');
        $body='';
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_POSTFIELDS=>json_encode($request,JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($ok===false || $status!==200) throw new RuntimeException('provider_failed');
        $response=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        foreach($response['candidates']??[] as $candidate) foreach($candidate['content']['parts']??[] as $part) if(!empty($part['thought'])) throw new RuntimeException('thought_not_displayable');
        return $response;
    };
}
function foundation(PDO $db): GeminiFoundation { return new GeminiFoundation($db,transport(),fn()=>time(),file_get_contents(stateDir().'/signing.key'),true); }
function simulate(GeminiFoundation $f,string $body): string {
    $id='demo-'.bin2hex(random_bytes(12));
    $raw=json_encode(['object'=>'whatsapp_business_account','entry'=>[['id'=>'synthetic-waba','changes'=>[['field'=>'messages','value'=>['metadata'=>['phone_number_id'=>'synthetic-phone'],'messages'=>[['id'=>$id,'timestamp'=>(string)time(),'type'=>'text','text'=>['body'=>$body]]]]]]]]],JSON_THROW_ON_ERROR);
    $f->ingest($raw,'sha256='.hash_hmac('sha256',$raw,file_get_contents(stateDir().'/signing.key')));
    $q=db()->prepare('INSERT INTO demo_provenance VALUES(?,?,?)');$q->execute([TENANT,$id,liveMode()?'LIVE Gemini':'MOCK']);
    $f->generate(TENANT,$id); return $id;
}
