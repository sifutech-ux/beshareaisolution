<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

require_once __DIR__ . '/AiReplyPolicy.php';
require_once __DIR__ . '/AiProductionSchema.php';

/** Operational metadata only: no tokens, knowledge documents or conversation content. */
final class PlatformAdminStore
{
    private ?bool $productionAvailable = null;
    public function __construct(private PDO $pdo, private array $config = [], private bool $metaAvailable = false) {}

    private function base(): string
    {
        return "SELECT b.id,b.public_id,b.name,b.owner_user_id,b.status AS business_status,b.created_at,b.updated_at,
            COALESCE(u.name,'') AS owner_name,COALESCE(u.email,'') AS owner_email,u.status AS owner_status,
            COALESCE(pa.status,'active') AS access_status,
            c.id AS connection_id,c.display_phone AS phone,c.token_state,c.webhook_state,c.registration_state,
            c.messaging_state,c.token_expires_at,c.updated_at AS connection_updated_at,
            (SELECT COUNT(*) FROM whatsapp_connections wc WHERE wc.business_id=b.id AND wc.disconnected_at IS NULL) AS connections_count,
            COALESCE(a.enabled,0) AS ai_enabled,k.published_revision,k.published_at,
            (SELECT MAX(ac.last_inbound_at) FROM ai_conversations ac WHERE ac.business_id=b.id) AS last_inbound_at,
            CASE WHEN c.id IS NULL THEN 'none'
              WHEN b.status<>'active' OR u.status<>'active' OR COALESCE(pa.status,'active')<>'active'
                OR c.token_state NOT IN ('stored','verified') OR c.registration_state='failed'
                OR (c.token_expires_at IS NOT NULL AND c.token_expires_at<=UTC_TIMESTAMP())
                OR op.status IN ('blocked','unknown') THEN 'attention'
              WHEN c.token_state='verified' AND c.webhook_state='subscribed' AND c.registration_state='registered' THEN 'ready'
              ELSE 'pending' END AS readiness
            FROM businesses b LEFT JOIN users u ON u.id=b.owner_user_id
            LEFT JOIN platform_business_access pa ON pa.business_id=b.id
            LEFT JOIN whatsapp_connections c ON c.id=(SELECT c2.id FROM whatsapp_connections c2
                WHERE c2.business_id=b.id AND c2.disconnected_at IS NULL ORDER BY c2.updated_at DESC,c2.id DESC LIMIT 1)
            LEFT JOIN platform_whatsapp_operations op ON op.connection_id=c.id AND op.business_id=b.id
            LEFT JOIN ai_reply_settings a ON a.business_id=b.id
            LEFT JOIN knowledge_centres k ON k.business_id=b.id";
    }

    public function customers(array $admin, string $search, string $filter, int $page): array
    {
        PlatformAccess::requireAdmin($this->pdo, (int) ($admin['user_id'] ?? 0));
        if (strlen($search)>160 || preg_match('//u',$search)!==1 || !in_array($filter,['all','attention','ready','suspended'],true) || $page<1 || $page>100000) {
            throw new AuthException('Carian pelanggan tidak sah.',400);
        }
        $conditions = [];
        $params = [];
        if (trim($search)!=='') {
            $term = '%' . strtr(trim($search), ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
            $conditions[] = "(name LIKE ? ESCAPE '!' OR owner_name LIKE ? ESCAPE '!' OR owner_email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!')";
            $params = [$term,$term,$term,$term];
        }
        if ($filter==='attention') { $conditions[] = "(readiness IN ('pending','attention') OR access_status='suspended')"; }
        elseif ($filter==='ready') { $conditions[] = "readiness='ready'"; }
        elseif ($filter==='suspended') { $conditions[] = "access_status='suspended'"; }
        $where = $conditions===[] ? '' : ' WHERE ' . implode(' AND ',$conditions);
        $from = '(' . $this->base() . ') customer';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $from . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1,(int) ceil($total/20));
        $page = min($page,$pages);
        $query = $this->pdo->prepare('SELECT * FROM ' . $from . $where . ' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET ' . (($page-1)*20));
        $query->execute($params);
        $customers = array_map(fn(array $row):array => $this->shapeCustomer($row),$query->fetchAll());
        $summary = $this->pdo->query("SELECT COUNT(*) AS customers,
            COALESCE(SUM(created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY),0) AS new_customers,
            COALESCE(SUM(ai_enabled=1),0) AS ai_enabled,
            COALESCE(SUM(access_status='suspended'),0) AS suspended_customers FROM " . $from)->fetch();
        $connections = $this->pdo->query("SELECT
            COALESCE(SUM(c.token_state='verified' AND c.webhook_state='subscribed' AND c.registration_state='registered'
                AND (c.token_expires_at IS NULL OR c.token_expires_at>UTC_TIMESTAMP()) AND b.status='active'
                AND u.status='active' AND COALESCE(p.status,'active')='active'
                AND (op.status IS NULL OR op.status NOT IN ('blocked','unknown'))),0) AS ready_connections,
            COALESCE(SUM(c.registration_state<>'registered' OR c.token_state<>'verified' OR c.webhook_state<>'subscribed'
                OR (c.token_expires_at IS NOT NULL AND c.token_expires_at<=UTC_TIMESTAMP())
                OR op.status IN ('blocked','unknown')),0) AS pending_connections
            FROM whatsapp_connections c JOIN businesses b ON b.id=c.business_id JOIN users u ON u.id=b.owner_user_id
            LEFT JOIN platform_business_access p ON p.business_id=b.id
            LEFT JOIN platform_whatsapp_operations op ON op.connection_id=c.id AND op.business_id=b.id
            WHERE c.disconnected_at IS NULL")->fetch();
        return ['summary'=>array_map('intval',array_merge($summary,$connections)), 'customers'=>$customers,
            'pagination'=>['page'=>$page,'page_size'=>20,'total'=>$total,'pages'=>$pages]];
    }

    public function details(array $admin, string $publicId): array
    {
        PlatformAccess::requireAdmin($this->pdo,(int)($admin['user_id']??0));
        $this->validPublicId($publicId);
        $query = $this->pdo->prepare($this->base().' WHERE b.public_id=?');
        $query->execute([$publicId]);
        $row = $query->fetch();
        if (!is_array($row)) { throw new AuthException('Pelanggan tidak dijumpai.',404); }
        $connections = $this->pdo->prepare("SELECT c.id,c.display_phone,c.token_state,c.webhook_state,c.registration_state,
            c.messaging_state,c.sync_contacts,c.sync_history,c.onboarded_at,c.token_expires_at,c.last_verified_at,c.last_error_code,
            op.status AS operation_status,op.error_code AS operation_error,op.registration_started_at,
            EXISTS(SELECT 1 FROM onboarding_sessions s WHERE s.business_id=c.business_id AND s.user_id=?
                AND s.status='completed' AND s.consumed_at IS NOT NULL AND s.reported_waba_id=c.waba_id
                AND s.reported_phone_number_id=c.phone_number_id AND (s.finish_event IS NULL OR s.finish_event='FINISH')) AS signup_completed
            FROM whatsapp_connections c LEFT JOIN platform_whatsapp_operations op ON op.connection_id=c.id AND op.business_id=c.business_id
            WHERE c.business_id=? AND c.disconnected_at IS NULL ORDER BY c.updated_at DESC,c.id DESC");
        $connections->execute([$row['owner_user_id'],$row['id']]);
        $items = array_map(fn(array $connection):array => $this->shapeConnection($connection,$row),$connections->fetchAll());
        $audit = $this->pdo->prepare("SELECT l.action,COALESCE(u.name,CASE WHEN l.actor_kind='system' THEN 'Sistem' ELSE 'Pengguna' END) AS actor_name,l.created_at
            FROM audit_logs l LEFT JOIN users u ON u.id=l.actor_user_id WHERE l.business_id=? ORDER BY l.id DESC LIMIT 25");
        $audit->execute([$row['id']]);
        $events = $audit->fetchAll();
        foreach ($events as &$event) { $event['created_at'] = self::stamp($event['created_at']); } unset($event);
        return ['customer'=>$this->shapeCustomer($row),'connections'=>$items,'ai'=>$this->aiState($row),
            'knowledge'=>['published'=>$row['published_revision']!==null,'published_at'=>self::stamp($row['published_at'])], 'audit'=>$events];
    }

    public function changeAccess(array $admin, string $publicId, string $status, string $expected): array
    {
        $admin = PlatformAccess::requireAdmin($this->pdo,(int)($admin['user_id']??0));
        if (!in_array($status,['active','suspended'],true)||!in_array($expected,['active','suspended'],true)) { throw new AuthException('Status akses tidak sah.',400); }
        $target = $this->business($publicId);
        $this->pdo->beginTransaction();
        try {
            // Match the existing worker's settings -> business order. No phone is deregistered.
            $settings = $this->pdo->prepare('SELECT business_id FROM ai_reply_settings WHERE business_id=? FOR UPDATE');
            $settings->execute([$target['id']]); $settings->fetch();
            $lock = $this->pdo->prepare('SELECT id,public_id,name,status,owner_user_id FROM businesses WHERE id=? FOR UPDATE');
            $lock->execute([$target['id']]);
            $currentBusiness = $lock->fetch();
            if (!is_array($currentBusiness)) { throw new AuthException('Pelanggan tidak dijumpai.',404); }
            $target = $currentBusiness;
            PlatformAccess::requireAdmin($this->pdo,$admin['user_id']);
            $query = $this->pdo->prepare('SELECT status FROM platform_business_access WHERE business_id=? FOR UPDATE');
            $query->execute([$target['id']]);
            $current = $query->fetchColumn();
            $current = $current===false ? 'active' : (string)$current;
            if ($current!==$expected) { throw new AuthException('Status pelanggan telah berubah. Muat semula butiran.',409); }
            if ($status!==$current) {
                $write = $this->pdo->prepare('INSERT INTO platform_business_access (business_id,status,updated_by,created_at,updated_at)
                    VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=?,updated_by=?,updated_at=UTC_TIMESTAMP()');
                $write->execute([$target['id'],$status,$admin['user_id'],$status,$admin['user_id']]);
                if ($status==='suspended') {
                    $stop = $this->pdo->prepare('UPDATE ai_reply_settings SET enabled=0,knowledge_revision=knowledge_revision+1,activation_at=NULL,updated_at=UTC_TIMESTAMP() WHERE business_id=?');
                    $stop->execute([$target['id']]);
                }
                PlatformAccess::audit($this->pdo,(int)$target['id'],$admin['user_id'],'platform_akses_'.$status,(int)$target['id'],['from'=>$current,'to'=>$status]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) { if($this->pdo->inTransaction()){$this->pdo->rollBack();} throw $error; }
        return ['status'=>$status,'message'=>$status==='active'
            ? 'Akses BeShare diaktifkan. Tetapan AI kekal seperti sedia ada.'
            : 'Akses BeShare digantung dan AI dihentikan. Sambungan Meta pelanggan dikekalkan.'];
    }

    public function setAi(array $admin,string $publicId,bool $enabled,int $revision): array
    {
        $admin = PlatformAccess::requireAdmin($this->pdo,(int)($admin['user_id']??0));
        if ($revision<0) { throw new AuthException('Versi tetapan tidak sah.',400); }
        $target = $this->business($publicId);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM ai_reply_settings WHERE business_id=? FOR UPDATE');
            $query->execute([$target['id']]);
            $settings = $query->fetch();
            if (!is_array($settings) || (int)$settings['knowledge_revision']!==$revision) { throw new AuthException('Tetapan AI belum tersedia atau telah berubah. Muat semula butiran.',409); }
            $lock = $this->pdo->prepare('SELECT id,public_id,name,status,owner_user_id FROM businesses WHERE id=? FOR UPDATE');
            $lock->execute([$target['id']]);
            $currentBusiness = $lock->fetch();
            if (!is_array($currentBusiness)) { throw new AuthException('Pelanggan tidak dijumpai.',404); }
            $target = $currentBusiness;
            PlatformAccess::requireAdmin($this->pdo,$admin['user_id']);
            if ($enabled) {
                PlatformAccess::assertBusinessActive($this->pdo,(int)$target['id']);
                $state = $this->aiState($target,$settings);
                if (!$state['can_enable']) { throw new AuthException($state['reason'],409); }
            }
            if ((bool)$settings['enabled']!==$enabled) {
                $query = $this->pdo->prepare('UPDATE ai_reply_settings SET enabled=?,knowledge_revision=knowledge_revision+1,
                    activation_at=?,updated_at=UTC_TIMESTAMP() WHERE business_id=? AND knowledge_revision=?');
                $query->execute([$enabled?1:0,$enabled?gmdate('Y-m-d H:i:s'):null,$target['id'],$revision]);
                PlatformAccess::audit($this->pdo,(int)$target['id'],$admin['user_id'],$enabled?'platform_ai_dihidupkan':'platform_ai_dihentikan',
                    (int)$settings['connection_id'],['revision'=>$revision+1]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) { if($this->pdo->inTransaction()){$this->pdo->rollBack();} throw $error; }
        return ['status'=>$enabled?'enabled':'disabled','message'=>$enabled
            ? 'AI dihidupkan menggunakan pengetahuan, skop balasan dan had yang telah diluluskan pelanggan.' : 'AI Auto Reply dihentikan.'];
    }

    public function assertTargetPhone(string $publicId,int $connectionId,string $expectedPhone): void
    {
        $target = $this->business($publicId);
        $query = $this->pdo->prepare('SELECT display_phone FROM whatsapp_connections WHERE business_id=? AND id=? AND disconnected_at IS NULL');
        $query->execute([$target['id'],$connectionId]);
        $phone = $query->fetchColumn();
        if ($phone===false || !hash_equals((string)$phone,$expectedPhone)) { throw new AuthException('Nombor sasaran telah berubah. Muat semula butiran pelanggan.',409); }
    }

    private function aiState(array $business,?array $settings=null): array
    {
        if ($settings===null) {
            $query = $this->pdo->prepare('SELECT * FROM ai_reply_settings WHERE business_id=?');
            $query->execute([$business['id']]);
            $found = $query->fetch(); $settings = is_array($found)?$found:null;
        }
        $productionAvailable = $this->productionAvailable ??= AiReplyPolicy::allBusinesses($this->config)
            && AiProductionSchema::available($this->pdo);
        $available = AiReplyPolicy::businessAllowed($this->config, (int) $business['id'])
            && (!AiReplyPolicy::allBusinesses($this->config) || $productionAvailable);
        $audience = $settings === null ? ($productionAvailable ? 'all' : 'test') : AiReplyPolicy::audienceMode($settings);
        $reason = '';
        if (!$available || !AiReplyPolicy::serverReady($this->config)) {
            $reason = 'AI Auto Reply belum dibuka untuk perniagaan ini.';
        } elseif ($settings===null || $settings['approved_by']===null || $settings['approved_at']===null
            || (int)$settings['approved_by']!==(int)$business['owner_user_id']
            || $settings['knowledge_ciphertext']===null || $settings['knowledge_nonce']===null
            || $settings['knowledge_tag']===null || $settings['knowledge_key_version']===null) {
            $reason = 'Pemilik pelanggan perlu meluluskan fakta perniagaan dan tetapan AI dahulu.';
        } else {
            if ($audience === 'all') {
                if (!$productionAvailable || !AiReplyPolicy::customerConsent($settings, (int) $business['owner_user_id'])) {
                    $reason = 'Pemilik pelanggan perlu meluluskan balasan kepada semua pelanggan dahulu.';
                }
            } elseif ($audience === 'test') {
                $allowlist = json_decode((string) $settings['allowlist_json'], true, 8);
                if (!is_array($allowlist) || !isset($allowlist[0]) || !is_string($allowlist[0])
                    || !AiReplyPolicy::recipientAllowed($settings, $allowlist[0], (int) $business['owner_user_id'], false)) {
                    $reason = $productionAvailable ? 'Pemilik pelanggan perlu menetapkan skop balasan AI dahulu.'
                        : 'Pelanggan perlu menetapkan penerima yang dibenarkan dahulu.';
                }
            } else {
                $reason = 'Pemilik pelanggan perlu mengesahkan semula skop balasan AI.';
            }
            $query = $this->pdo->prepare("SELECT id FROM whatsapp_connections WHERE business_id=? AND id=? AND disconnected_at IS NULL
                AND token_state='verified' AND webhook_state='subscribed' AND registration_state='registered'
                AND (token_expires_at IS NULL OR token_expires_at>UTC_TIMESTAMP())");
            $query->execute([$business['id'],$settings['connection_id']]);
            if($query->fetchColumn()===false) { $reason='Nombor WhatsApp untuk AI belum sedia digunakan.'; }
        }
        if (($business['business_status']??$business['status']??'')!=='active'
            || PlatformAccess::businessStatus($this->pdo,(int)$business['id'])!=='active') {
            $reason='Akses perniagaan perlu aktif sebelum AI boleh dihidupkan.';
        }
        return ['available'=>$available,'production_available'=>$productionAvailable,'audience_mode'=>$audience,
            'audience_approved'=>$settings!==null&&$audience==='all'&&AiReplyPolicy::customerConsent($settings,(int)$business['owner_user_id']),
            'enabled'=>$settings!==null&&(int)$settings['enabled']===1,
            'can_enable'=>$reason==='','reason'=>$reason,'revision'=>$settings===null?0:(int)$settings['knowledge_revision']];
    }

    private function shapeCustomer(array $row): array
    {
        $times = array_filter([$row['updated_at'],$row['connection_updated_at'],$row['last_inbound_at']],fn($value):bool=>is_string($value)&&$value!=='');
        return ['public_id'=>$row['public_id'],'name'=>$row['name'],'owner_name'=>$row['owner_name'],'owner_email'=>$row['owner_email'],
            'created_at'=>self::stamp($row['created_at']),'access_status'=>$row['access_status'],'business_status'=>$row['business_status'],
            'connections_count'=>(int)$row['connections_count'],'phone'=>$row['phone']??'','readiness'=>$row['readiness'],
            'ai_enabled'=>(int)$row['ai_enabled']===1,'knowledge_published'=>$row['published_revision']!==null,
            'last_activity'=>$times===[]?null:self::stamp(max($times))];
    }

    private function shapeConnection(array $c,array $business): array
    {
        $mode = ($c['sync_contacts']!=='not_applicable'||$c['sync_history']!=='not_applicable'||$c['onboarded_at']!==null)?'coexistence':'cloud_api';
        $active = $business['business_status']==='active'&&$business['access_status']==='active'&&$business['owner_status']==='active';
        $token = $c['token_state']==='verified'
            &&($c['token_expires_at']===null||strtotime($c['token_expires_at'].' UTC')>time());
        $ready = $active&&$token&&$c['webhook_state']==='subscribed'&&$c['registration_state']==='registered'
            &&!in_array($c['operation_status'],['blocked','unknown'],true);
        $reason = '';
        if(!$active) { $reason='Akses perniagaan belum aktif.'; }
        elseif(!$token) { $reason='Pelanggan perlu menyambungkan semula kebenaran Meta.'; }
        elseif(in_array($c['operation_status'],['checking','registering'],true)) { $reason='Proses sebelumnya sedang berjalan. Kemas kini status untuk mendapatkan hasilnya.'; }
        elseif($c['operation_status']==='unknown') { $reason='Keputusan pengaktifan belum pasti. Kemas kini status sebelum tindakan seterusnya.'; }
        elseif($c['operation_status']==='blocked'&&$c['registration_state']==='registered') { $reason='Semakan sambungan memerlukan perhatian. Kemas kini status untuk mendapatkan keadaan semasa.'; }
        elseif($c['webhook_state']!=='subscribed') { $reason='Langganan sambungan memerlukan perhatian.'; }
        elseif($c['registration_state']==='failed') { $reason='Meta memerlukan tindakan pada nombor ini.'; }
        elseif(!$ready && $mode==='coexistence') { $reason='Nombor ini menggunakan sambungan WhatsApp Business App. Kemas kini status sambungannya.'; }
        elseif(!$ready && !(bool)$c['signup_completed']) { $reason='Kebenaran pendaftaran melalui Meta belum direkodkan bagi nombor ini. Kemas kini status untuk semakan.'; }
        elseif(!$ready) { $reason='Nombor telah disambungkan; pengaktifan masih perlu dilengkapkan.'; }
        if(!$this->metaAvailable) { $reason='Perkhidmatan pengaktifan WhatsApp belum tersedia.'; }
        return ['id'=>(int)$c['id'],'phone'=>$c['display_phone']??'','mode'=>$mode,'token_state'=>$c['token_state'],
            'webhook_state'=>$c['webhook_state'],'registration_state'=>$c['registration_state'],'messaging_state'=>$c['messaging_state'],
            'readiness'=>$ready?'ready':(!$token||$c['registration_state']==='failed'||$c['operation_status']==='unknown'?'attention':'pending'),
            'reason'=>$reason,'can_refresh'=>$this->metaAvailable&&$active&&$token,
            'can_activate'=>$this->metaAvailable&&$active&&$token&&!$ready&&$mode==='cloud_api'
                &&$c['webhook_state']==='subscribed'&&$c['registration_state']!=='failed'&&(bool)$c['signup_completed']
                &&$c['registration_started_at']===null&&!in_array($c['operation_status'],['checking','registering','unknown'],true),
            'operation_status'=>$c['operation_status'],'last_verified_at'=>self::stamp($c['last_verified_at'])];
    }

    private function business(string $publicId): array
    {
        $this->validPublicId($publicId);
        $query=$this->pdo->prepare('SELECT id,public_id,name,status,owner_user_id FROM businesses WHERE public_id=?');
        $query->execute([$publicId]); $row=$query->fetch();
        if(!is_array($row)){throw new AuthException('Pelanggan tidak dijumpai.',404);} return $row;
    }

    private function validPublicId(string $publicId): void
    {
        if(preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/',$publicId)!==1){throw new AuthException('Pelanggan tidak sah.',400);}
    }

    private static function stamp(mixed $value): ?string
    {
        return is_string($value)&&$value!==''?str_replace(' ','T',$value).'Z':null;
    }
}
