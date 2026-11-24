<?php
declare(strict_types=1);
namespace BesharOs;

use PDO;

final class ProductAccess
{
    public static function grant(PDO $db, int $id): array
    {
        $q = $db->prepare("SELECT id,meta_json FROM audit_logs WHERE business_id=? AND action='os_product_access' ORDER BY id DESC LIMIT 1");
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) { return ['state'=>'pending','until'=>0,'revision'=>0]; }
        $v = json_decode((string)$r['meta_json'], true, 8, JSON_THROW_ON_ERROR);
        return ['state'=>$v['state'],'until'=>(int)$v['until'],'revision'=>(int)$r['id']];
    }

    public static function usable(array $grant): bool
    {
        return $grant['state']==='free' || (in_array($grant['state'],['paid','setup'],true) && $grant['until'] > time());
    }

    public static function directory(PDO $db, string $q, int $page): array
    {
        $offset = (max(1,min(100000,$page))-1)*25;
        $sql = "SELECT b.id,b.public_id,b.name,u.email,b.created_at FROM businesses b JOIN users u ON u.id=b.owner_user_id
                WHERE (LOWER(u.email) LIKE ? OR LOWER(b.name) LIKE ?) ORDER BY b.id DESC LIMIT 26 OFFSET ".$offset;
        $stmt = $db->prepare($sql);
        $term = '%'.strtolower(substr($q,0,120)).'%';
        $stmt->execute([$term,$term]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $users = [];
        foreach (array_slice($rows,0,25) as $row) {
            $users[] = ['id'=>$row['public_id'],'email'=>$row['email'],'name'=>$row['name'],'date'=>$row['created_at'],
                'grants'=>['os'=>self::grant($db,(int)$row['id'])]];
        }
        return ['users'=>$users,'next'=>count($rows)>25];
    }

    public static function change(PDO $db, array $admin, array $input): array
    {
        $state=$input['state']??null;
        if (!in_array($state,['paid','free','pending','suspended','setup'],true) || !is_int($input['revision']??null)) {
            throw new AuthException('Pilihan akses tidak sah.',400);
        }
        $until = $state==='setup' ? time()+3600 : 0;
        $receipt = '';
        if ($state==='paid') {
            $date = $input['until']??'';
            $receipt = $input['receipt']??'';
            $tz = new \DateTimeZone('Asia/Kuala_Lumpur');
            $d = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz) : false;
            if (!$d || $d->format('Y-m-d')!==$date || ($until=$d->modify('+1 day')->getTimestamp())<=time()
                || !is_string($receipt) || !preg_match('/\Ain_[a-zA-Z0-9]{1,100}\z/',$receipt)) {
                throw new AuthException('Semak ID invois Stripe dan tarikh akhir bayaran.',400);
            }
        }
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT id FROM businesses WHERE public_id=? FOR UPDATE');
            $q->execute([$input['id']??'']);
            $id=(int)$q->fetchColumn();
            if (!$id) { throw new AuthException('Perniagaan tidak dijumpai.',404); }
            if (self::grant($db,$id)['revision']!==$input['revision']) { throw new AuthException('Rekod telah berubah. Muat semula.',409); }
            PlatformAccess::audit($db,$id,$admin['user_id'],'os_product_access',$id,['state'=>$state,'until'=>$until,'receipt'=>$receipt]);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
        return ['ok'=>true];
    }
}
