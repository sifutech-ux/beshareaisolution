<?php
declare(strict_types=1);

namespace BesharOs;

final class OwnershipAccess
{
    /**
     * Future application gate for businesses.status and businesses.owner_user_id.
     * The session supplies business_id. A requested business id is ignored.
     * owner_user_id is not an access grant. admin and staff cannot change status
     * or ownership. The current owner uses separate statements:
     * begin_transfer sets provisioning without changing owner_user_id;
     * reassign_owner changes owner_user_id only while status stays provisioning;
     * finish_transfer leaves provisioning without changing owner_user_id;
     * suspend_or_resume swaps active and suspended without changing owner_user_id.
     * SQL triggers remain the backstop for a combined statement.
     *
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $session
     * @param array{kind:string,business_id?:int,next_status?:string,next_owner_user_id?:int} $action
     */
    public static function decide(array $session, array $action): string
    {
        if (isset($action['business_id']) && $action['business_id'] !== $session['business_id']) {
            return 'denied';
        }
        if ($session['role'] !== 'owner' || $session['user_id'] !== $session['owner_user_id']) {
            return 'denied';
        }
        $status = $session['status'];
        $ownerChanges = array_key_exists('next_owner_user_id', $action)
            && $action['next_owner_user_id'] !== $session['owner_user_id'];
        $next = $action['next_status'] ?? '';
        if ($action['kind'] === 'begin_transfer') {
            if ($ownerChanges || $next !== 'provisioning' || !in_array($status, ['active', 'suspended'], true)) {
                return 'denied';
            }
            return 'allowed';
        }
        if ($action['kind'] === 'reassign_owner') {
            if ($status !== 'provisioning' || $ownerChanges === false || ($next !== '' && $next !== 'provisioning')) {
                return 'denied';
            }
            return 'allowed';
        }
        if ($action['kind'] === 'finish_transfer') {
            if ($status !== 'provisioning' || $ownerChanges || !in_array($next, ['active', 'suspended'], true)) {
                return 'denied';
            }
            return 'allowed';
        }
        if ($action['kind'] === 'suspend_or_resume') {
            $pair = $status . '>' . $next;
            if ($ownerChanges || ($pair !== 'active>suspended' && $pair !== 'suspended>active')) {
                return 'denied';
            }
            return 'allowed';
        }
        return 'denied';
    }
}
