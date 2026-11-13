<?php
declare(strict_types=1);
namespace BesharOs;

use PDO;
use Throwable;

/** Owner-only hosted checkout. A redirect is never proof of payment. */
final class StripeBilling
{
    public const VERSION = '2026-08-26.dahlia';
    public const ORIGIN = 'https://beshareaisolution.com';
    public const EVENTS = ['checkout.session.completed','checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed','invoice.paid','invoice.payment_failed',
        'customer.subscription.created','customer.subscription.updated','customer.subscription.deleted',
        'customer.subscription.paused','customer.subscription.resumed'];
    private \Closure $transport;
    private int $now;

    public function __construct(private PDO $pdo, private array $config, ?callable $transport = null, ?int $now = null)
    {
        self::validateConfig($config);
        $this->transport = $transport === null ? $this->request(...) : \Closure::fromCallable($transport);
        $this->now = $now ?? time();
    }

    public static function config(): ?array
    {
        $path = getenv('BESHARE_BILLING_CONFIG') ?: dirname(__DIR__,3).'/private/beshare-billing.json';
        $real = realpath($path); $root = realpath(dirname(__DIR__,2));
        if ($real === false || $root === false || !is_file($real) || str_starts_with($real,$root.'/')
            || preg_match('#(?:^|/)public_html(?:/|$)#',$real) || filesize($real)>65536) { return null; }
        $raw = file_get_contents($real);
        $data = is_string($raw) ? json_decode($raw,true) : null;
        return is_array($data) ? $data : null;
    }

    private static function validateConfig(array $c): void
    {
        $mode = $c['mode'] ?? '';
        if (($c['enabled'] ?? false) !== true || !in_array($mode,['test','live'],true)
            || !str_starts_with((string)($c['secret_key'] ?? ''),'rk_'.$mode.'_')
            || ($mode === 'live' && ($c['live_approved'] ?? false) !== true)
            || preg_match('/\Aprice_[A-Za-z0-9]+\z/',(string)($c['price'] ?? '')) !== 1
            || preg_match('/\Aprice_[A-Za-z0-9]+\z/',(string)($c['setup_price'] ?? '')) !== 1) {
            throw new AuthException('Pembayaran sedang disediakan.',503);
        }
    }

    public static function event(string $raw, string $header, string $secret, bool $live, int $now): array
    {
        if (!str_starts_with($secret,'whsec_')) { throw new AuthException('Webhook belum tersedia.',503); }
        if (strlen($raw)>500000 || strlen($header)>4096) { throw new AuthException('Webhook tidak sah.',400); }
        $times=[];$signatures=[];
        foreach(explode(',',$header) as $part) {
            $pair=explode('=',$part,2);
            if(count($pair)!==2){continue;}
            if($pair[0]==='t'){$times[]=$pair[1];}
            if($pair[0]==='v1'){$signatures[]=$pair[1];}
        }
        if(count($times)!==1 || !ctype_digit($times[0]) || abs($now-(int)$times[0])>300) {
            throw new AuthException('Tandatangan webhook tidak sah.',400);
        }
        $expected=hash_hmac('sha256',$times[0].'.'.$raw,$secret);$valid=false;
        foreach($signatures as $sig){$valid=$valid || hash_equals($expected,$sig);}
        if(!$valid){throw new AuthException('Tandatangan webhook tidak sah.',400);}
        $event=json_decode($raw,true);
        if(!is_array($event) || ($event['livemode']??null)!==$live
            || preg_match('/\Aevt_[A-Za-z0-9]+\z/',(string)($event['id']??''))!==1) {
            throw new AuthException('Persekitaran webhook tidak sepadan.',400);
        }
        return $event;
    }

    private function sql(string $sql, array $values=[]): \PDOStatement
    {
        $q=$this->pdo->prepare($sql);$q->execute($values);return $q;
    }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
    private function row(int $id, bool $lock=false): ?array
    {
        $r=$this->sql('SELECT * FROM os_billing WHERE business_id=? AND mode=?'.($lock?$this->lock():''),[$id,$this->config['mode']])->fetch(PDO::FETCH_ASSOC);
        return is_array($r)?$r:null;
    }
    private function update(int $id, array $data): void
    {
        $data['updated_at']=$this->now;
        $this->sql('UPDATE os_billing SET '.implode(',',array_map(static fn($key)=>$key.'=?',array_keys($data))).' WHERE business_id=? AND mode=?',
            array_merge(array_values($data),[$id,$this->config['mode']]));
    }
    private function owner(array $actor, ?string $expected=null): void
    {
        if(($actor['role']??'')!=='owner' || ($actor['user_id']??0)!==($actor['owner_user_id']??-1) || ($actor['status']??'')!=='active') {
            throw new AuthException('Hanya pemilik boleh mengurus langganan.',403);
        }
        if($expected!==null && $expected!==$actor['public_id']) { throw new AuthException('Ruang perniagaan telah berubah. Muat semula.',409); }
        $allow=$this->config['business_allowlist']??[];
        if(!in_array($actor['public_id'],$allow,true) && !($this->config['mode']==='live' && in_array('*',$allow,true))) {
            throw new AuthException('Pembayaran belum dibuka untuk perniagaan ini.',403);
        }
    }
    public function ready(int $id): bool
    {
        return $this->sql("SELECT c.id FROM whatsapp_connections c INNER JOIN ai_reply_settings a ON a.business_id=c.business_id AND a.connection_id=c.id
          WHERE c.business_id=? AND c.token_state='verified' AND c.webhook_state='subscribed' AND c.disconnected_at IS NULL
          AND EXISTS(SELECT 1 FROM ai_messages m WHERE m.business_id=c.business_id AND m.connection_id=c.id AND m.direction='in' AND m.status='received')
          AND EXISTS(SELECT 1 FROM ai_messages m WHERE m.business_id=c.business_id AND m.connection_id=c.id AND m.direction='out' AND m.status IN ('sent','delivered','read') AND m.wamid IS NOT NULL) LIMIT 1",[$id])->fetchColumn()!==false;
    }
    public function view(array $actor): array
    {
        $this->owner($actor);$row=$this->row($actor['business_id'])??[];
        return ['available'=>true,'mode'=>$this->config['mode'],'status'=>$row['status']??'none',
            'active'=>($row['status']??'')==='active' && (int)($row['paid_until']??0)>$this->now,
            'access_until'=>(int)($row['paid_until']??0),'has_customer'=>!empty($row['customer']),
            'cancel_at_period_end'=>(bool)($row['cancel_at_end']??false),'ready'=>$this->ready($actor['business_id']),
            'monthly_amount'=>19900,'setup_amount'=>!empty($row['setup_paid'])?0:29900,'currency'=>'myr'];
    }
    public function checkout(array $actor,string $expected): array
    {
        $this->owner($actor,$expected);$id=$actor['business_id'];
        if(!$this->ready($id)) { throw new AuthException('Lengkapkan ujian terima mesej dan balasan AI pada nombor WhatsApp anda dahulu.',409); }
        $this->pdo->beginTransaction();
        try {
            // Serialize creation with the existing tenant row, including the first checkout.
            $this->sql('SELECT id FROM businesses WHERE id=?'.$this->lock(),[$id])->fetch();
            $row=$this->row($id,true);
            if($row===null){$this->sql('INSERT INTO os_billing(business_id,mode) VALUES (?,?)',[$id,$this->config['mode']]);$row=$this->row($id,true);}
            if($row['subscription']) {
                $this->reconcile($id,$row['subscription']);$row=$this->row($id,true);
                if(!in_array($row['status'],['canceled','incomplete_expired'],true)) { throw new AuthException('Langganan sudah wujud. Gunakan Urus langganan.',409); }
                $this->update($id,['subscription'=>null,'checkout'=>null,'attempt'=>null,'attempt_at'=>0,'paid_until'=>0]);$row=$this->row($id,true);
            }
            if($row['checkout']) {
                $old=$this->api('checkout/sessions/'.$row['checkout']);
                if(($old['status']??'')==='open'){$this->pdo->commit();return ['ok'=>true,'url'=>self::url($old['url']??'','checkout.stripe.com')];}
                if(($old['status']??'')==='complete') {
                    $sub=self::id($old['subscription']??null,'sub');
                    if($sub!==null){$this->reconcile($id,$sub);$this->pdo->commit();}
                    throw new AuthException('Bayaran sedang disahkan. Muat semula status langganan.',409);
                }
                if(($old['status']??'')!=='expired'){throw new AuthException('Checkout sedang disahkan.',409);}
                $this->update($id,['attempt'=>null,'checkout'=>null,'attempt_at'=>0]);$row=$this->row($id,true);
            }
            if(!$row['customer']) {
                $customer=$this->api('customers',['email'=>$actor['email'],'metadata[beshare_product]'=>'os',
                    'metadata[beshare_owner]'=>$actor['public_id']],'beshare-os-customer-'.$this->config['mode'].'-'.$actor['public_id']);
                $customerId=self::id($customer['id']??null,'cus');
                if($customerId===null){throw new AuthException('Pelanggan Stripe tidak sah.',503);}
                $this->update($id,['customer'=>$customerId]);
            }
            if(!$row['attempt']){$this->update($id,['attempt'=>bin2hex(random_bytes(16)),'attempt_at'=>$this->now]);}
            $this->pdo->commit();$this->pdo->beginTransaction();$row=$this->row($id,true);
            if($this->now-(int)$row['attempt_at']>=23*3600 && !$row['checkout']) {throw new AuthException('Checkout memerlukan semakan BeShare sebelum dicuba lagi.',409);}
            $this->price($this->config['price'],19900,'month');
            if(!(bool)$row['setup_paid']){$this->price($this->config['setup_price'],29900,null);}
            $data=['mode'=>'subscription','customer'=>$row['customer'],'line_items[0][price]'=>$this->config['price'],
                'line_items[0][quantity]'=>1,'success_url'=>self::ORIGIN.'/whatsapp/ruang.html?bil=berjaya#bil',
                'cancel_url'=>self::ORIGIN.'/whatsapp/ruang.html?bil=batal#bil','client_reference_id'=>$row['attempt'],
                'metadata[beshare_product]'=>'os','metadata[beshare_owner]'=>$actor['public_id'],'metadata[beshare_attempt]'=>$row['attempt'],
                'subscription_data[metadata][beshare_product]'=>'os','subscription_data[metadata][beshare_owner]'=>$actor['public_id'],
                'subscription_data[metadata][beshare_attempt]'=>$row['attempt']];
            if(!(bool)$row['setup_paid']){$data['line_items[1][price]']=$this->config['setup_price'];$data['line_items[1][quantity]']=1;}
            $session=$this->api('checkout/sessions',$data,'beshare-os-checkout-'.$row['attempt']);
            $sessionId=self::id($session['id']??null,'cs');
            if($sessionId===null || self::id($session['customer']??null,'cus')!==$row['customer']){throw new AuthException('Checkout tidak sepadan.',503);}
            $url=self::url($session['url']??'','checkout.stripe.com');$this->update($id,['checkout'=>$sessionId]);$this->pdo->commit();
            return ['ok'=>true,'url'=>$url];
        } catch(Throwable $e) {if($this->pdo->inTransaction()){$this->pdo->rollBack();}throw $e;}
    }
    public function portal(array $actor,string $expected): array
    {
        $this->owner($actor,$expected);$row=$this->row($actor['business_id']);
        if(!$row || !$row['customer']){throw new AuthException('Belum ada langganan untuk diurus.',409);}
        $session=$this->api('billing_portal/sessions',['customer'=>$row['customer'],'configuration'=>$this->config['portal'],
            'return_url'=>self::ORIGIN.'/whatsapp/ruang.html#bil'],'beshare-os-portal-'.bin2hex(random_bytes(16)));
        return ['ok'=>true,'url'=>self::url($session['url']??'','billing.stripe.com')];
    }
    private function reconcile(int $id,string $subscription): void
    {
        $row=$this->row($id,true);if(!$row){throw new AuthException('Padanan akaun belum tersedia.',503);}
        $public=(string)$this->sql('SELECT public_id FROM businesses WHERE id=?',[$id])->fetchColumn();
        $sub=$this->api('subscriptions/'.$subscription);$meta=$sub['metadata']??[];
        if(self::id($sub['customer']??null,'cus')!==$row['customer'] || ($meta['beshare_product']??'')!=='os'
            || ($meta['beshare_owner']??'')!==$public || ($meta['beshare_attempt']??'')!==$row['attempt']) {throw new AuthException('Langganan tidak sepadan.',400);}
        if(!$row['checkout']){throw new AuthException('Checkout belum disimpan. Stripe akan mencuba semula.',503);}
        $session=$this->api('checkout/sessions/'.$row['checkout']);
        if(self::id($session['subscription']??null,'sub')!==$subscription || ($session['status']??'')!=='complete'
            || self::id($session['customer']??null,'cus')!==$row['customer']) {throw new AuthException('Checkout belum mengesahkan langganan.',503);}
        $items=$sub['items']['data']??[];
        if(count($items)!==1 || self::id($items[0]['price']??null,'price')!==$this->config['price'] || ($items[0]['quantity']??0)!==1) {throw new AuthException('Pelan langganan tidak sepadan.',400);}
        $paid=(int)$row['paid_until'];$start=(int)$row['period_start'];$setup=(int)$row['setup_paid'];$status=(string)($sub['status']??'none');
        $invoiceId=self::id($sub['latest_invoice']??null,'in');
        if($invoiceId && $status==='active') {
            $inv=$this->api('invoices/'.$invoiceId);
            $parent=$inv['parent']['subscription_details']['subscription']??$inv['subscription']??null;
            if(($inv['status']??'')==='paid' && self::id($inv['customer']??null,'cus')===$row['customer'] && self::id($parent,'sub')===$subscription) {
                foreach($inv['lines']['data']??[] as $line) {
                    $price=self::id($line['pricing']['price_details']['price']??$line['price']??null,'price');
                    if($price===$this->config['setup_price']){$setup=1;}
                    if($price===$this->config['price'] && (int)($line['period']['end']??0)===(int)($items[0]['current_period_end']??0)
                        && (int)($line['period']['end']??0)>$paid) {$paid=(int)$line['period']['end'];$start=(int)($items[0]['current_period_start']??0);}
                }
            }
        }
        $this->update($id,['subscription'=>$subscription,'status'=>$status,'paid_until'=>$paid,'period_start'=>$start,
            'setup_paid'=>$setup,'cancel_at_end'=>(int)(($sub['cancel_at_period_end']??false)===true)]);
    }
    public function webhook(array $event): array
    {
        if(!in_array($event['type']??'',self::EVENTS,true)){return ['ok'=>true,'ignored'=>true];}
        $obj=$event['data']['object']??[];$customer=self::id($obj['customer']??null,'cus');
        $this->pdo->beginTransaction();
        try {
            if($this->sql('SELECT event_id FROM os_billing_events WHERE event_id=?',[$event['id']])->fetchColumn()!==false){$this->pdo->commit();return ['ok'=>true,'duplicate'=>true];}
            $id=$this->sql('SELECT business_id FROM os_billing WHERE customer=? AND mode=?',[$customer,$this->config['mode']])->fetchColumn();
            if($id===false){$this->pdo->commit();return ['ok'=>true,'ignored'=>true];}
            $id=(int)$id;$row=$this->row($id,true);
            // Another worker may have processed the event while this one waited for its tenant lock.
            if($this->sql('SELECT event_id FROM os_billing_events WHERE event_id=?',[$event['id']])->fetchColumn()!==false){$this->pdo->commit();return ['ok'=>true,'duplicate'=>true];}
            $sub=str_starts_with($event['type'],'customer.subscription.')?self::id($obj['id']??null,'sub'):
                self::id($obj['subscription']??$obj['parent']['subscription_details']['subscription']??null,'sub');
            if($sub!==null){$current=$this->api('subscriptions/'.$sub);if(($current['metadata']['beshare_attempt']??'')===$row['attempt']){$this->reconcile($id,$sub);}}
            $this->sql('INSERT INTO os_billing_events(event_id,processed_at) VALUES (?,?)',[$event['id'],$this->now]);$this->pdo->commit();return ['ok'=>true];
        }catch(Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}throw $e;}
    }
    private function price(string $id,int $amount,?string $interval): void
    {
        $price=$this->api('prices/'.$id);
        if(($price['active']??false)!==true || ($price['currency']??'')!=='myr' || ($price['unit_amount']??0)!==$amount
            || ($price['recurring']['interval']??null)!==$interval || ($interval!==null && ($price['recurring']['interval_count']??0)!==1)) {
            throw new AuthException('Harga pelan tidak sepadan. Hubungi BeShare.',503);
        }
    }
    public static function id(mixed $value,string $prefix): ?string
    {
        if(is_array($value)){$value=$value['id']??null;}
        return is_string($value) && preg_match('/\A'.preg_quote($prefix,'/').'_[A-Za-z0-9_]+\z/',$value)===1?$value:null;
    }
    public static function url(string $url,string $host): string
    {
        $p=parse_url($url);
        if(!is_array($p) || ($p['scheme']??'')!=='https' || ($p['host']??'')!==$host || isset($p['user']) || isset($p['pass']) || (isset($p['port'])&&$p['port']!==443)) {throw new AuthException('Pautan pembayaran tidak sah.',503);}
        return $url;
    }
    private function api(string $path,?array $data=null,?string $key=null): array
    {
        $result=($this->transport)($path,$data,$key);
        if(!is_array($result) || (array_key_exists('livemode',$result) && $result['livemode']!==($this->config['mode']==='live'))) {throw new AuthException('Persekitaran Stripe tidak sepadan.',503);}
        return $result;
    }
    private function request(string $path,?array $data,?string $key): array
    {
        if(preg_match('#\A[a-z_/]+(?:/[A-Za-z0-9_]+)?\z#',$path)!==1){throw new AuthException('Permintaan Stripe tidak sah.',503);}
        $curl=curl_init('https://api.stripe.com/v1/'.$path);
        if($curl===false){throw new AuthException('Stripe tidak dapat dihubungi.',503);}
        $headers=['Authorization: Bearer '.$this->config['secret_key'],'Stripe-Version: '.self::VERSION];
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS];
        if($data!==null){if(!$key){throw new AuthException('Kunci permintaan diperlukan.',503);}$headers[]='Idempotency-Key: '.$key;$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($data);}
        $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($curl,$options);$raw=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if(!is_string($raw) || $code<200 || $code>=300){throw new AuthException('Stripe belum dapat dihubungi. Cuba semula sebentar lagi.',503);}
        $result=json_decode($raw,true);if(!is_array($result)){throw new AuthException('Jawapan Stripe tidak sah.',503);}return $result;
    }
}
