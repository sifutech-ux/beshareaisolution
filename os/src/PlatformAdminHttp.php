<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

/** An explicit platform boundary; never derives operator power from tenant roles. */
final class PlatformAdminHttp
{
    public function __construct(private PDO $pdo,private RateLimit $rates,private PlatformAdminStore $store,private ?AdminWhatsAppActivation $activation=null) {}

    public function handle(string $method,string $action,array &$session,array $input,array $headers,string $remote,string $host,string $scheme='https'): array
    {
        $method=strtoupper($method);
        if(!in_array($method,['GET','POST'],true)){throw new AuthException('Kaedah tidak dibenarkan.',405);}
        $admin=PlatformAccess::requireAdmin($this->pdo,(int)($session['user_id']??0));
        if($method==='GET') {
            $data=match($action) {
                'sesi'=>['admin'=>['name'=>$admin['name'],'email'=>$admin['email'],'role'=>$admin['platform_role']]],
                'pelanggan'=>$this->store->customers($admin,$this->text($input,'q',''),$this->text($input,'filter','all'),$this->page($input['page']??1)),
                'butiran'=>$this->store->details($admin,$this->text($input,'public_id')),
                default=>throw new AuthException('Kaedah tidak dibenarkan.',405),
            };
            return $this->respond($session,$data);
        }
        $this->origin($headers,$host,$scheme);
        Csrf::check($session,(string)($headers['x-csrf-token']??''));
        if(!in_array($action,['refresh','activate','access','ai'],true)){throw new AuthException('Tindakan tidak dikenali.',404);}
        if(($input['confirmed']??false)!==true){throw new AuthException('Sahkan pelanggan dan tindakan yang dipilih.',400);}
        $publicId=$this->text($input,'public_id');
        $key='platform|'.$admin['user_id'].'|'.$action.'|'.$remote;
        if($this->rates->tooMany($key)){throw new AuthException('Terlalu banyak tindakan. Tunggu sebentar sebelum mencuba lagi.',429);}
        $this->rates->hit($key);
        if($action==='access') {
            $result=$this->store->changeAccess($admin,$publicId,$this->text($input,'status'),$this->text($input,'expected_status'));
        } elseif($action==='ai') {
            if(!is_bool($input['enabled']??null)||!is_int($input['expected_revision']??null)){throw new AuthException('Tetapan AI tidak sah.',400);}
            $result=$this->store->setAi($admin,$publicId,$input['enabled'],$input['expected_revision']);
        } else {
            if(!$this->activation instanceof AdminWhatsAppActivation){throw new AuthException('Perkhidmatan pengaktifan WhatsApp belum tersedia.',503);}
            $id=$input['connection_id']??null;
            if(!is_int($id)||$id<1||$id>9007199254740991){throw new AuthException('Sambungan tidak sah.',400);}
            $this->store->assertTargetPhone($publicId,$id,$this->text($input,'expected_phone'));
            $result=$action==='activate'?$this->activation->activate($admin,$publicId,$id):$this->activation->refresh($admin,$publicId,$id);
        }
        return $this->respond($session,['result'=>$result]);
    }

    private function respond(array &$session,array $data): array
    {
        return ['status'=>200,'body'=>array_merge(['ok'=>true,'csrf'=>Csrf::issue($session)],$data)];
    }

    private function text(array $input,string $key,?string $default=null): string
    {
        $value=$input[$key]??$default;
        if(!is_string($value)){throw new AuthException('Medan permintaan tidak sah.',400);} return $value;
    }

    private function page(mixed $value): int
    {
        if((is_int($value)&&$value>=1&&$value<=100000)||(is_string($value)&&preg_match('/\A[1-9][0-9]{0,4}\z/',$value)===1)){return (int)$value;}
        throw new AuthException('Halaman tidak sah.',400);
    }

    private function origin(array $headers,string $host,string $scheme): void
    {
        $origin=$headers['origin']??'';
        if($origin===''){return;}
        $actual=parse_url($origin); $expected=parse_url($scheme.'://'.$host);
        if(!in_array($scheme,['https','http'],true)||!is_array($actual)||!is_array($expected)||($actual['scheme']??'')!==$scheme
            ||isset($actual['user'])||isset($actual['pass'])||isset($actual['query'])||isset($actual['fragment'])
            ||(isset($actual['path'])&&$actual['path']!=='')
            ||!isset($actual['host'],$expected['host'])||strcasecmp($actual['host'],$expected['host'])!==0
            ||(int)($actual['port']??($scheme==='https'?443:80))!==(int)($expected['port']??($scheme==='https'?443:80))) {
            throw new AuthException('Permintaan daripada laman lain ditolak.',403);
        }
    }
}
