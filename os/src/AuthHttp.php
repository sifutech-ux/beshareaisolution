<?php
declare(strict_types=1);

namespace BesharOs;

use PDO;

final class AuthHttp
{
    public function __construct(private PDO $pdo, private RateLimit $rates, private ?WhatsAppGateway $whatsapp = null)
    {
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @return array{status:int,regenerate:bool,body:array<string,mixed>}
     */
    public function handle(string $method, string $action, array &$session, array $body, array $headers, string $remote, string $host, string $sessionKey = ''): array
    {
        unset($body['business_id'], $body['user_id'], $body['role_id'], $body['owner_user_id']);
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'POST') {
            throw new AuthException('Kaedah tidak dibenarkan.', 405);
        }
        if ($method === 'POST') {
            $this->assertOrigin($headers, $host);
            Csrf::check($session, (string) ($headers['x-csrf-token'] ?? ''));
        }
        if ($method === 'GET' && $action === 'sesi') {
            return $this->respond($session, false, $this->anonymousOrCurrent($session));
        }
        if ($method === 'GET' && $action === 'ahli') {
            $actor = $this->actor($session);
            return $this->respond($session, false, $this->payload($actor));
        }
        if ($method === 'GET' && $action === 'audit') {
            $actor = $this->actor($session);
            return $this->respond($session, false, $this->payload($actor));
        }
        if ($method === 'GET' && $action === 'peti') {
            $actor = $this->actor($session);
            return $this->respond($session, false, $this->withInbox($actor));
        }
        if ($method !== 'POST') {
            throw new AuthException('Kaedah tidak dibenarkan.', 405);
        }
        if ($action === 'masuk') {
            return $this->login($session, $body, $remote);
        }
        if ($action === 'daftar') {
            return $this->register($session, $body, $remote);
        }
        if ($action === 'keluar') {
            $session = [];
            $token = Csrf::rotate($session);
            return [
                'status' => 200,
                'regenerate' => true,
                'body' => ['ok' => true, 'csrf' => $token, 'user' => null],
            ];
        }
        $actor = $this->actor($session);
        if ($action === 'perniagaan') {
            $created = Tenants::provision($this->pdo, $actor['user_id'], $this->text($body, 'name'));
            $session['user_id'] = $created['user_id'];
            $session['business_id'] = $created['business_id'];
            return $this->respond($session, true, $this->payload($created));
        }
        if ($action === 'pilih') {
            $selected = Membership::byPublicId($this->pdo, $actor['user_id'], $this->text($body, 'public_id'));
            $session['user_id'] = $selected['user_id'];
            $session['business_id'] = $selected['business_id'];
            return $this->respond($session, true, $this->payload($selected));
        }
        if ($action === 'ahli') {
            Tenants::addMember(
                $this->pdo,
                $actor,
                $this->text($body, 'name'),
                $this->text($body, 'email'),
                $this->text($body, 'password'),
                $this->text($body, 'role')
            );
            return $this->respond($session, false, $this->payload($this->actor($session)));
        }
        if ($action === 'buang') {
            Tenants::removeMember($this->pdo, $actor, $this->text($body, 'email'));
            return $this->respond($session, false, $this->payload($this->actor($session)));
        }
        if ($action === 'peranan') {
            Tenants::changeRole($this->pdo, $actor, $this->text($body, 'email'), $this->text($body, 'role'));
            return $this->respond($session, false, $this->payload($this->actor($session)));
        }
        if ($action === 'status') {
            $kind = $this->text($body, 'kind');
            $next = $this->text($body, 'next_status');
            if ($kind === 'begin_transfer') {
                $next = 'provisioning';
            }
            $updated = Tenants::changeStatus($this->pdo, $actor, $kind, $next, $this->optional($body, 'owner_email'));
            $session['business_id'] = $updated['business_id'];
            return $this->respond($session, false, $this->payload($updated));
        }
        if ($action === 'tetapan') {
            Tenants::rename($this->pdo, $actor, $this->text($body, 'name'));
            return $this->respond($session, false, $this->payload($this->actor($session)));
        }
        if ($action === 'sambung' || $action === 'peristiwa' || $action === 'tukar' || $action === 'segera' || $action === 'selesai-kenalan') {
            return $this->whatsapp($action, $session, $actor, $body, $remote, $sessionKey);
        }
        throw new AuthException('Tindakan tidak dikenali.', 404);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $body
     * @return array{status:int,regenerate:bool,body:array<string,mixed>}
     */
    private function login(array &$session, array $body, string $remote): array
    {
        $email = strtolower(trim($this->text($body, 'email')));
        $password = $this->text($body, 'password');
        $key = 'login|' . $email . '|' . $remote;
        if ($this->rates->tooMany($key)) {
            throw new AuthException('Terlalu banyak percubaan. Cuba lagi kemudian.', 429);
        }
        try {
            $user = Accounts::authenticate($this->pdo, $email, $password);
        } catch (AuthException $error) {
            if ($error->status() === 401) {
                $this->rates->hit($key);
            }
            throw $error;
        }
        $this->rates->clear($key);
        $context = Membership::primary($this->pdo, $user['id']);
        $session = [
            'user_id' => $context['user_id'],
            'business_id' => $context['business_id'],
        ];
        Accounts::audit($this->pdo, $context['business_id'], $context['user_id'], 'masuk', 'user', $context['user_id']);
        return $this->respond($session, true, $this->payload($context));
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $body
     * @return array{status:int,regenerate:bool,body:array<string,mixed>}
     */
    private function register(array &$session, array $body, string $remote): array
    {
        $key = 'daftar|' . $remote;
        if ($this->rates->tooMany($key)) {
            throw new AuthException('Terlalu banyak percubaan. Cuba lagi kemudian.', 429);
        }
        $this->rates->hit($key);
        $context = Accounts::register(
            $this->pdo,
            $this->text($body, 'name'),
            $this->text($body, 'email'),
            $this->text($body, 'password'),
            $this->text($body, 'business')
        );
        $session = [
            'user_id' => $context['user_id'],
            'business_id' => $context['business_id'],
        ];
        return $this->respond($session, true, $this->payload($context));
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function anonymousOrCurrent(array &$session): array
    {
        $userId = (int) ($session['user_id'] ?? 0);
        $businessId = (int) ($session['business_id'] ?? 0);
        if ($userId < 1 || $businessId < 1) {
            return ['ok' => true, 'user' => null];
        }
        try {
            return $this->payload(Membership::require($this->pdo, $userId, $businessId));
        } catch (AuthException) {
            unset($session['user_id'], $session['business_id']);
            return ['ok' => true, 'user' => null];
        }
    }

    /** @param array<string,mixed> $session */
    private function actor(array &$session): array
    {
        $userId = (int) ($session['user_id'] ?? 0);
        $businessId = (int) ($session['business_id'] ?? 0);
        if ($userId < 1 || $businessId < 1) {
            throw new AuthException('Sesi diperlukan.', 401);
        }
        try {
            return Membership::require($this->pdo, $userId, $businessId);
        } catch (AuthException) {
            unset($session['user_id'], $session['business_id']);
            throw new AuthException('Sesi tidak sah.', 401);
        }
    }

    /** @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string} $context
     *  @return array<string,mixed>
     */
    private function payload(array $context): array
    {
        $memberships = [];
        foreach (Membership::list($this->pdo, $context['user_id']) as $row) {
            $memberships[] = [
                'public_id' => $row['public_id'],
                'name' => $row['business_name'],
                'role' => $row['role'],
                'status' => $row['status'],
            ];
        }
        $members = [];
        $audit = [];
        if ($context['role'] === 'owner' || $context['role'] === 'admin') {
            $members = Tenants::members($this->pdo, $context);
            $audit = Tenants::audit($this->pdo, $context);
        }
        return [
            'ok' => true,
            'user' => [
                'name' => $context['name'],
                'email' => $context['email'],
                'role' => $context['role'],
            ],
            'business' => [
                'public_id' => $context['public_id'],
                'name' => $context['business_name'],
                'status' => $context['status'],
            ],
            'memberships' => $memberships,
            'members' => $members,
            'audit' => $audit,
            'connection' => $this->connection($context),
            'inbox_ready' => $this->whatsapp instanceof WhatsAppGateway,
        ];
    }

    /**
     * @param array<string,mixed> $session
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string} $actor
     * @param array<string,mixed> $body
     * @return array{status:int,regenerate:bool,body:array<string,mixed>}
     */
    private function whatsapp(string $action, array &$session, array $actor, array $body, string $remote, string $sessionKey): array
    {
        if (!$this->whatsapp instanceof WhatsAppGateway) {
            throw new AuthException('Sambungan Meta belum dikonfigurasi.', 503);
        }
        if ($action === 'sambung') {
            $key = 'sambung|' . $actor['user_id'] . '|' . $remote;
            if ($this->rates->tooMany($key)) {
                throw new AuthException('Terlalu banyak percubaan. Cuba lagi kemudian.', 429);
            }
            $this->rates->hit($key);
            $started = $this->whatsapp->begin($actor, $sessionKey);
            $payload = $this->payload($actor);
            $payload['state'] = $started['state'];
            return $this->respond($session, false, $payload);
        }
        if ($action === 'peristiwa') {
            $this->whatsapp->record($actor, $sessionKey, $body);
            return $this->respond($session, false, $this->payload($this->actor($session)));
        }
        if ($action === 'segera') {
            $key = 'segera|' . $actor['user_id'] . '|' . $remote;
            if ($this->rates->tooMany($key)) {
                throw new AuthException('Terlalu banyak percubaan. Cuba lagi kemudian.', 429);
            }
            $this->rates->hit($key);
            $sync = $this->whatsapp->authorizeSync($actor, $this->text($body, 'sync_type'));
            $payload = $this->payload($this->actor($session));
            $payload['sync'] = $sync;
            return $this->respond($session, false, $payload);
        }
        if ($action === 'selesai-kenalan') {
            $key = 'selesai|' . $actor['user_id'] . '|' . $remote;
            if ($this->rates->tooMany($key)) {
                throw new AuthException('Terlalu banyak percubaan. Cuba lagi kemudian.', 429);
            }
            $this->rates->hit($key);
            $sync = $this->whatsapp->settleContacts($actor);
            $payload = $this->payload($this->actor($session));
            $payload['sync'] = $sync;
            return $this->respond($session, false, $payload);
        }
        $key = 'tukar|' . $actor['user_id'] . '|' . $remote;
        if ($this->rates->tooMany($key)) {
            throw new AuthException('Terlalu banyak percubaan. Cuba lagi kemudian.', 429);
        }
        $this->rates->hit($key);
        $this->whatsapp->exchangeCode($actor, $sessionKey, $this->text($body, 'state'), $this->text($body, 'code'));
        return $this->respond($session, false, $this->payload($this->actor($session)));
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string,email:string,name:string,business_name:string,public_id:string} $actor
     * @return array<string,mixed>
     */
    private function withInbox(array $actor): array
    {
        $payload = $this->payload($actor);
        $inbox = $this->whatsapp instanceof WhatsAppGateway
            ? $this->whatsapp->inbox($actor)
            : ['conversations' => [], 'contacts' => []];
        $payload['conversations'] = $inbox['conversations'];
        $payload['contacts'] = $inbox['contacts'];
        return $payload;
    }

    /**
     * @param array{user_id:int,business_id:int,role:string,owner_user_id:int,status:string} $actor
     * @return array{token:string,webhook:string,registration:string,messaging:string,note:string,phone:string,linked:bool}
     */
    private function connection(array $actor): array
    {
        if (!$this->whatsapp instanceof WhatsAppGateway) {
            return [
                'token' => 'Belum',
                'webhook' => 'Belum',
                'registration' => 'Belum',
                'messaging' => 'Belum',
                'note' => '',
                'phone' => '',
                'linked' => false,
            ];
        }
        return $this->whatsapp->view($actor);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $body
     * @return array{status:int,regenerate:bool,body:array<string,mixed>}
     */
    private function respond(array &$session, bool $regenerate, array $body): array
    {
        $body['csrf'] = $regenerate ? Csrf::rotate($session) : Csrf::issue($session);
        return ['status' => 200, 'regenerate' => $regenerate, 'body' => $body];
    }

    /** @param array<string,string> $headers */
    private function assertOrigin(array $headers, string $host): void
    {
        $origin = $headers['origin'] ?? '';
        if ($origin === '') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $expected = parse_url('http://' . $host, PHP_URL_HOST);
        if (!is_string($originHost) || !is_string($expected) || strcasecmp($originHost, $expected) !== 0) {
            throw new AuthException('Permintaan ditolak.', 403);
        }
    }

    /** @param array<string,mixed> $body */
    private function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        if (!is_string($value)) {
            throw new AuthException('Medan tidak sah.', 400);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function optional(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw new AuthException('Medan tidak sah.', 400);
        }
        return $body[$key];
    }
}
