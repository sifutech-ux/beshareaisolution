<?php
declare(strict_types=1);

use BesharOs\Accounts;
use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\Csrf;
use BesharOs\Db;
use BesharOs\Identifiers;
use BesharOs\Membership;
use BesharOs\Password;
use BesharOs\RateLimit;
use BesharOs\SessionCookie;
use BesharOs\SqlScript;
use BesharOs\Tenants;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/SqlScript.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures++;
        fwrite(STDOUT, "FAIL " . $name . "\n");
        return;
    }
    fwrite(STDOUT, "OK " . $name . "\n");
};

$cookie = SessionCookie::parameters(true);
$check('session cookie is http only', $cookie['httponly'] === true);
$check('session cookie is lax', $cookie['samesite'] === 'Lax');
$check('session cookie path is workspace', $cookie['path'] === '/whatsapp/');
$check('secure flag follows https', $cookie['secure'] === true && SessionCookie::parameters(false)['secure'] === false);

$tooShort = false;
try {
    Password::hash('pendek');
} catch (AuthException) {
    $tooShort = true;
}
$check('short password is rejected', $tooShort);
$hash = Password::hash('kata-laluan-yang-panjang');
$check('password verifies', Password::verify('kata-laluan-yang-panjang', $hash) === true);
$check('wrong password does not verify', Password::verify('kata-laluan-yang-lain', $hash) === false);
$check('dummy hash is not empty', Password::dummyHash() !== '');

$check('email is stored lowercase', Identifiers::email('  Nama@Kedai.COM ') === 'nama@kedai.com');
$badEmail = false;
try {
    Identifiers::email('bukan-emel');
} catch (AuthException) {
    $badEmail = true;
}
$check('bad email is rejected', $badEmail);
$check('public id is 26 characters', strlen(Identifiers::publicId()) === 26);

$rateDir = sys_get_temp_dir() . '/beshare-os-rate-' . bin2hex(random_bytes(4));
$limit = new RateLimit($rateDir, 3, 900);
$check('rate limit starts open', $limit->tooMany('login|a') === false);
$limit->hit('login|a');
$limit->hit('login|a');
$limit->hit('login|a');
$check('rate limit closes after the budget', $limit->tooMany('login|a') === true);
$rateFile = $rateDir . '/' . hash('sha256', 'login|a') . '.json';
$rateMode = is_file($rateFile) ? (fileperms($rateFile) & 0777) : 0;
$rateBody = is_file($rateFile) ? (string) file_get_contents($rateFile) : '';
$rateEvents = json_decode($rateBody, true);
$rateInts = is_array($rateEvents) && count($rateEvents) === 3;
foreach (is_array($rateEvents) ? $rateEvents : [] as $event) {
    if (!is_int($event)) {
        $rateInts = false;
    }
}
$check('rate file is private and has no identifier', $rateMode === 0600 && $rateInts && !str_contains($rateBody, 'login') && !str_contains($rateBody, '@'));
$dirMode = fileperms($rateDir) & 0777;
$check('rate directory is private', $dirMode === 0700);
$limit->clear('login|a');
$check('rate limit clears', $limit->tooMany('login|a') === false);

$session = [];
$token = Csrf::issue($session);
$csrfOk = true;
try {
    Csrf::check($session, $token);
} catch (AuthException) {
    $csrfOk = false;
}
$check('csrf accepts the issued token', $csrfOk);
$csrfBad = false;
try {
    Csrf::check($session, 'bukan-token');
} catch (AuthException $error) {
    $csrfBad = $error->status() === 403;
}
$check('csrf rejects a different token', $csrfBad);

$dashboard = (string) file_get_contents(dirname(__DIR__, 2) . '/whatsapp/ruang.js');
$pintu = (string) file_get_contents(dirname(__DIR__, 2) . '/whatsapp/akaun/pintu.php');
$guard = (string) file_get_contents(dirname(__DIR__, 2) . '/os/.htaccess');
$check('demo session key is gone', !str_contains($dashboard, 'beshare-os-demo') && !str_contains($dashboard, 'Peranan demo'));
$check('dashboard auth actions call the api', str_contains($dashboard, 'callApi("masuk"') && str_contains($dashboard, 'callApi("daftar"') && str_contains($dashboard, 'callApi("keluar"') && str_contains($dashboard, 'callApi("ahli"') && str_contains($dashboard, 'callApi("buang"') && str_contains($dashboard, 'callApi("status"') && str_contains($dashboard, 'callApi("tetapan"') && str_contains($dashboard, 'callApi("pilih"') && str_contains($dashboard, 'callApi("sesi")'));
$check('client address is not taken from a forwarded header', str_contains($pintu, 'REMOTE_ADDR') && !str_contains($pintu, 'FORWARDED_FOR') && !str_contains($pintu, 'X-Real-IP') && !str_contains($pintu, 'X_REAL_IP'));
$check('login rotates the php session id', str_contains($pintu, 'session_regenerate_id(true)') && str_contains($pintu, "session.use_strict_mode"));
$check('library tree is denied over http', str_contains($guard, 'Require all denied'));
$check('rate storage resolves outside the web root', str_contains($pintu, 'RateLimit::resolveDirectory()') && !str_contains($pintu, 'os/var/rate'));
privateConfigChecks($check);

$pdo = lab();
if (!$pdo instanceof PDO) {
    fwrite(STDOUT, "FAIL lab database\n");
    exit(1);
}
$name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$check('tests use the isolated lab', $name === 'auth_lab');
if ($name !== 'auth_lab') {
    exit(1);
}

$http = new AuthHttp($pdo, new RateLimit($rateDir . '-http', 3, 900));
$host = 'lab.test';
$remote = '127.0.0.1';

$anon = [];
$issued = $http->handle('GET', 'sesi', $anon, [], [], $remote, $host);
$check('anonymous session has no user', array_key_exists('user', $issued['body']) && $issued['body']['user'] === null);
$csrf = (string) $issued['body']['csrf'];

$denied = false;
try {
    $http->handle('POST', 'ahli', $anon, ['email' => 'a@kedai.test', 'business_id' => 1], ['x-csrf-token' => $csrf], $remote, $host);
} catch (AuthException $error) {
    $denied = $error->status() === 401;
}
$check('member list without a session is denied', $denied);

$missing = false;
try {
    $http->handle('POST', 'masuk', $anon, ['email' => 'tiada@kedai.test', 'password' => 'kata-laluan-yang-panjang'], ['x-csrf-token' => $csrf], $remote, $host);
} catch (AuthException $error) {
    $missing = $error->status() === 401 && $error->getMessage() === 'E-mel atau kata laluan tidak sah.';
}
$check('unknown login does not reveal the account', $missing);

$origin = [];
Csrf::issue($origin);
$blocked = false;
try {
    $http->handle('POST', 'masuk', $origin, ['email' => 'a@kedai.test', 'password' => 'kata-laluan-yang-panjang'], [
        'x-csrf-token' => $origin['csrf'],
        'origin' => 'https://evil.test',
    ], $remote, $host);
} catch (AuthException $error) {
    $blocked = $error->status() === 403;
}
$check('cross origin login is rejected', $blocked);

$owner = [];
Csrf::issue($owner);
$csrfBeforeRegister = $owner['csrf'];
$created = $http->handle('POST', 'daftar', $owner, [
    'name' => 'Pemilik Satu',
    'email' => 'Pemilik@Kedai.TEST',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Satu',
    'business_id' => 999,
], ['x-csrf-token' => $owner['csrf']], $remote, $host);
$check('register logs the owner in', ($created['body']['user']['role'] ?? '') === 'owner');
$check('register stores the lowercase email', ($created['body']['user']['email'] ?? '') === 'pemilik@kedai.test');
$check('register activates the business', ($created['body']['business']['status'] ?? '') === 'active');
$check('register rotates the session', $created['regenerate'] === true && ($created['body']['csrf'] ?? '') !== $csrfBeforeRegister);
$check('register ignores a client business id', !array_key_exists('business_id', $created['body']) && !array_key_exists('user_id', $created['body']));
$publicA = (string) $created['body']['business']['public_id'];
$csrfA = (string) $created['body']['csrf'];

$dup = false;
try {
    $again = [];
    Csrf::issue($again);
    $http->handle('POST', 'daftar', $again, [
        'name' => 'Pemilik Satu',
        'email' => 'pemilik@kedai.test',
        'password' => 'kata-laluan-yang-panjang',
        'business' => 'Kedai Lain',
    ], ['x-csrf-token' => $again['csrf']], '127.0.0.2', $host);
} catch (AuthException $error) {
    $dup = $error->status() === 409;
}
$check('duplicate email is rejected', $dup);

$badPassword = false;
try {
    $http->handle('POST', 'masuk', $owner, ['email' => 'pemilik@kedai.test', 'password' => 'salah-salah-salah'], ['x-csrf-token' => $csrfA], $remote, $host);
} catch (AuthException $error) {
    $badPassword = $error->status() === 401;
}
$check('wrong password is rejected', $badPassword);
for ($attempt = 0; $attempt < 2; $attempt++) {
    try {
        $http->handle('POST', 'masuk', $owner, ['email' => 'pemilik@kedai.test', 'password' => 'salah-salah-salah'], ['x-csrf-token' => $csrfA], $remote, $host);
    } catch (AuthException) {
    }
}
$locked = false;
try {
    $http->handle('POST', 'masuk', $owner, ['email' => 'pemilik@kedai.test', 'password' => 'kata-laluan-yang-panjang'], ['x-csrf-token' => $csrfA], $remote, $host);
} catch (AuthException $error) {
    $locked = $error->status() === 429;
}
$check('login rate limit blocks the next attempt', $locked);

$fresh = new AuthHttp($pdo, new RateLimit($rateDir . '-fresh', 5, 900));
$loginSession = [];
Csrf::issue($loginSession);
$logged = $fresh->handle('POST', 'masuk', $loginSession, [
    'email' => 'pemilik@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
], ['x-csrf-token' => $loginSession['csrf']], '10.0.0.8', $host);
$check('valid login restores the session', ($logged['body']['user']['email'] ?? '') === 'pemilik@kedai.test' && $logged['regenerate'] === true);
$csrfA = (string) $logged['body']['csrf'];
$owner = $loginSession;

$invited = $fresh->handle('POST', 'ahli', $owner, [
    'name' => 'Staf Satu',
    'email' => 'staf@kedai.test',
    'password' => 'staf-laluan-panjang',
    'role' => 'staff',
    'business_id' => 1,
], ['x-csrf-token' => $csrfA], $remote, $host);
$csrfA = (string) $invited['body']['csrf'];
$emails = array_column($invited['body']['members'], 'email');
$check('owner can add staff', in_array('staf@kedai.test', $emails, true));

$escalated = false;
try {
    $fresh->handle('POST', 'peranan', $owner, [
        'email' => 'staf@kedai.test',
        'role' => 'owner',
    ], ['x-csrf-token' => $csrfA], $remote, $host);
} catch (AuthException $error) {
    $escalated = $error->status() === 403;
}
$check('owner cannot grant a second owner role', $escalated);
$roleRow = $pdo->prepare('SELECT role FROM business_users bu INNER JOIN users u ON u.id = bu.user_id WHERE u.email = ?');
$roleRow->execute(['staf@kedai.test']);
$check('staff role stays staff', $roleRow->fetchColumn() === 'staff');

$adminAdd = $fresh->handle('POST', 'ahli', $owner, [
    'name' => 'Admin Satu',
    'email' => 'admin@kedai.test',
    'password' => 'admin-laluan-panjang',
    'role' => 'admin',
], ['x-csrf-token' => $csrfA], $remote, $host);
$csrfA = (string) $adminAdd['body']['csrf'];

$staffSession = [];
Csrf::issue($staffSession);
$staffLogin = $fresh->handle('POST', 'masuk', $staffSession, [
    'email' => 'staf@kedai.test',
    'password' => 'staf-laluan-panjang',
], ['x-csrf-token' => $staffSession['csrf']], '10.0.0.9', $host);
$staffCsrf = (string) $staffLogin['body']['csrf'];
$check('staff session has no member list', ($staffLogin['body']['members'] ?? null) === []);
$staffDenied = false;
try {
    $fresh->handle('POST', 'peranan', $staffSession, [
        'email' => 'admin@kedai.test',
        'role' => 'owner',
    ], ['x-csrf-token' => $staffCsrf], '10.0.0.9', $host);
} catch (AuthException $error) {
    $staffDenied = $error->status() === 403;
}
$check('staff cannot change roles', $staffDenied);
$staffStatus = false;
try {
    $fresh->handle('POST', 'status', $staffSession, [
        'kind' => 'suspend_or_resume',
        'next_status' => 'suspended',
    ], ['x-csrf-token' => $staffCsrf], '10.0.0.9', $host);
} catch (AuthException $error) {
    $staffStatus = $error->status() === 403;
}
$check('staff cannot change tenant status', $staffStatus);

$adminSession = [];
Csrf::issue($adminSession);
$adminLogin = $fresh->handle('POST', 'masuk', $adminSession, [
    'email' => 'admin@kedai.test',
    'password' => 'admin-laluan-panjang',
], ['x-csrf-token' => $adminSession['csrf']], '10.0.0.10', $host);
$adminCsrf = (string) $adminLogin['body']['csrf'];
$check('admin can read members', count($adminLogin['body']['members'] ?? []) === 3);
$adminMutate = false;
try {
    $fresh->handle('POST', 'buang', $adminSession, ['email' => 'staf@kedai.test'], ['x-csrf-token' => $adminCsrf], '10.0.0.10', $host);
} catch (AuthException $error) {
    $adminMutate = $error->status() === 403;
}
$check('admin cannot remove a member', $adminMutate);
$adminSuspend = false;
try {
    $fresh->handle('POST', 'status', $adminSession, [
        'kind' => 'suspend_or_resume',
        'next_status' => 'suspended',
    ], ['x-csrf-token' => $adminCsrf], '10.0.0.10', $host);
} catch (AuthException $error) {
    $adminSuspend = $error->status() === 403;
}
$check('admin cannot suspend the tenant', $adminSuspend);

$other = [];
Csrf::issue($other);
$otherCreated = $fresh->handle('POST', 'daftar', $other, [
    'name' => 'Pemilik Dua',
    'email' => 'dua@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Dua',
], ['x-csrf-token' => $other['csrf']], '10.1.0.2', $host);
$csrfB = (string) $otherCreated['body']['csrf'];
$publicB = (string) $otherCreated['body']['business']['public_id'];
$check('second tenant is separate', $publicB !== $publicA);

$cross = false;
try {
    $fresh->handle('POST', 'buang', $other, [
        'email' => 'staf@kedai.test',
        'business_id' => 1,
    ], ['x-csrf-token' => $csrfB], '10.1.0.2', $host);
} catch (AuthException $error) {
    $cross = $error->status() === 404;
}
$still = $pdo->prepare('SELECT COUNT(*) FROM business_users bu INNER JOIN users u ON u.id = bu.user_id WHERE u.email = ?');
$still->execute(['staf@kedai.test']);
$check('cross tenant delete is ignored', $cross && (int) $still->fetchColumn() === 1);

$peek = false;
try {
    $fresh->handle('POST', 'pilih', $other, ['public_id' => $publicA, 'business_id' => 1], ['x-csrf-token' => $csrfB], '10.1.0.2', $host);
} catch (AuthException $error) {
    $peek = $error->status() === 404;
}
$check('another tenant public id is not selectable', $peek);
$check('session stays on its own tenant', (string) $pdo->query('SELECT public_id FROM businesses WHERE id = ' . (int) $other['business_id'])->fetchColumn() === $publicB);

$tampered = $staffSession;
$tampered['role'] = 'owner';
$tampered['business_id'] = (int) $other['business_id'];
$tamperDenied = false;
try {
    $fresh->handle('GET', 'ahli', $tampered, [], [], '10.0.0.9', $host);
} catch (AuthException $error) {
    $tamperDenied = $error->status() === 401;
}
$check('session business id without membership is rejected', $tamperDenied);
$check('rejected session drops the business', !isset($tampered['business_id']));

$ownerRemove = false;
try {
    $fresh->handle('POST', 'buang', $owner, ['email' => 'pemilik@kedai.test'], ['x-csrf-token' => $csrfA], $remote, $host);
} catch (AuthException $error) {
    $ownerRemove = $error->status() === 403;
}
$check('owner membership cannot be removed', $ownerRemove);

$renamed = $fresh->handle('POST', 'tetapan', $owner, ['name' => 'Kedai Satu Baharu'], ['x-csrf-token' => $csrfA], $remote, $host);
$check('owner can rename the tenant', ($renamed['body']['business']['name'] ?? '') === 'Kedai Satu Baharu');
$csrfA = (string) $renamed['body']['csrf'];
$staffRename = false;
$staffSession = [];
Csrf::issue($staffSession);
$staffLogin = $fresh->handle('POST', 'masuk', $staffSession, [
    'email' => 'staf@kedai.test',
    'password' => 'staf-laluan-panjang',
], ['x-csrf-token' => $staffSession['csrf']], '10.0.0.11', $host);
try {
    $fresh->handle('POST', 'tetapan', $staffSession, ['name' => 'Nama Curian'], ['x-csrf-token' => $staffLogin['body']['csrf']], '10.0.0.11', $host);
} catch (AuthException $error) {
    $staffRename = $error->status() === 403;
}
$check('staff cannot rename the tenant', $staffRename);

$signal = false;
try {
    $pdo->exec(
        'UPDATE businesses SET owner_user_id = ' . (int) $other['user_id']
        . ' WHERE id = ' . (int) $owner['business_id']
    );
} catch (PDOException $error) {
    $signal = str_contains($error->getMessage(), 'owner_change_requires_provisioning');
}
$check('active owner change is rejected by the database', $signal);
$insertSignal = false;
try {
    $pdo->exec(
        "INSERT INTO businesses (public_id, name, status, owner_user_id, created_at, updated_at)
         VALUES ('0123456789ABCDEFGHJKMNPQRS', 'Terus aktif', 'active', " . (int) $owner['user_id'] . ", UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
} catch (PDOException $error) {
    $insertSignal = str_contains($error->getMessage(), 'business_must_start_provisioning');
}
$check('a business cannot be inserted already active', $insertSignal);

$early = false;
try {
    $fresh->handle('POST', 'status', $owner, [
        'kind' => 'reassign_owner',
        'next_status' => 'active',
        'owner_email' => 'admin@kedai.test',
        'business_id' => (int) $other['business_id'],
    ], ['x-csrf-token' => $csrfA], $remote, $host);
} catch (AuthException $error) {
    $early = $error->status() === 403;
}
$ownerStill = (int) $pdo->query('SELECT owner_user_id FROM businesses WHERE public_id = ' . $pdo->quote($publicA))->fetchColumn();
$userStill = (int) $pdo->query('SELECT id FROM users WHERE email = \'pemilik@kedai.test\'')->fetchColumn();
$check('owner cannot be reassigned while active', $early && $ownerStill === $userStill);

$began = $fresh->handle('POST', 'status', $owner, [
    'kind' => 'begin_transfer',
    'next_status' => 'active',
], ['x-csrf-token' => $csrfA], $remote, $host);
$check('transfer opens only as provisioning', ($began['body']['business']['status'] ?? '') === 'provisioning');
$csrfA = (string) $began['body']['csrf'];
$moved = $fresh->handle('POST', 'status', $owner, [
    'kind' => 'reassign_owner',
    'owner_email' => 'admin@kedai.test',
], ['x-csrf-token' => $csrfA], $remote, $host);
$check('owner can be reassigned while provisioning', ($moved['body']['user']['role'] ?? '') === 'admin');
$csrfA = (string) $moved['body']['csrf'];
$former = false;
try {
    $fresh->handle('POST', 'status', $owner, [
        'kind' => 'finish_transfer',
        'next_status' => 'active',
    ], ['x-csrf-token' => $csrfA], $remote, $host);
} catch (AuthException $error) {
    $former = $error->status() === 403;
}
$check('former owner cannot finish the transfer', $former);

$newOwner = [];
Csrf::issue($newOwner);
$newLogin = $fresh->handle('POST', 'masuk', $newOwner, [
    'email' => 'admin@kedai.test',
    'password' => 'admin-laluan-panjang',
], ['x-csrf-token' => $newOwner['csrf']], '10.4.0.1', $host);
$finished = $fresh->handle('POST', 'status', $newOwner, [
    'kind' => 'finish_transfer',
    'next_status' => 'active',
], ['x-csrf-token' => $newLogin['body']['csrf']], '10.4.0.1', $host);
$newOwnerId = (int) $pdo->query('SELECT id FROM users WHERE email = \'admin@kedai.test\'')->fetchColumn();
$nowOwner = (int) $pdo->query('SELECT owner_user_id FROM businesses WHERE public_id = ' . $pdo->quote($publicA))->fetchColumn();
$check('new owner can finish the transfer', ($finished['body']['business']['status'] ?? '') === 'active' && $nowOwner === $newOwnerId);

$kept = $fresh->handle('POST', 'ahli', $other, [
    'name' => 'Tidak digunakan',
    'email' => 'staf@kedai.test',
    'password' => 'kata-laluan-yang-baru-sekali',
    'role' => 'staff',
], ['x-csrf-token' => $csrfB], '10.1.0.2', $host);
$check('existing user joins without a new account', count($kept['body']['members'] ?? []) === 2);
$samePassword = [];
Csrf::issue($samePassword);
$stillOriginal = $fresh->handle('POST', 'masuk', $samePassword, [
    'email' => 'staf@kedai.test',
    'password' => 'staf-laluan-panjang',
], ['x-csrf-token' => $samePassword['csrf']], '10.5.0.1', $host);
$check('existing password is unchanged', ($stillOriginal['body']['user']['email'] ?? '') === 'staf@kedai.test');

$pdo->exec("UPDATE users SET status = 'disabled' WHERE email = 'dua@kedai.test'");
$disabled = false;
$disabledSession = [];
Csrf::issue($disabledSession);
try {
    $fresh->handle('POST', 'masuk', $disabledSession, [
        'email' => 'dua@kedai.test',
        'password' => 'kata-laluan-yang-panjang',
    ], ['x-csrf-token' => $disabledSession['csrf']], '10.6.0.1', $host);
} catch (AuthException $error) {
    $disabled = $error->status() === 401 && $error->getMessage() === 'E-mel atau kata laluan tidak sah.';
}
$check('disabled user gets the same login error', $disabled);

$loggedOut = $fresh->handle('POST', 'keluar', $newOwner, [], ['x-csrf-token' => $finished['body']['csrf']], '10.4.0.1', $host);
$check('logout clears the user', array_key_exists('user', $loggedOut['body']) && $loggedOut['body']['user'] === null && !isset($newOwner['user_id']));
$check('logout rotates the session id flag', $loggedOut['regenerate'] === true);

$gate = new AuthHttp($pdo, new RateLimit($rateDir . '-gate', 3, 900));
$open = static function () use ($gate, $host): array {
    $session = [];
    $issued = $gate->handle('GET', 'sesi', $session, [], [], '10.8.0.1', $host);
    $session['csrf'] = $issued['body']['csrf'];
    return $session;
};
$deny = static function (string $name, callable $attempt, int $status) use ($check): void {
    $ok = false;
    try {
        $attempt();
    } catch (AuthException $error) {
        $ok = $error->status() === $status;
    }
    $check($name, $ok);
};
$alih = $open();
$madeAlih = $gate->handle('POST', 'daftar', $alih, [
    'name' => 'Pemilik Alih',
    'email' => 'alih@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Alih',
], ['x-csrf-token' => $alih['csrf']], '10.8.0.2', $host);
$csrfAlih = (string) $madeAlih['body']['csrf'];
$gate->handle('POST', 'ahli', $alih, [
    'name' => 'Anak Staf',
    'email' => 'anak@kedai.test',
    'password' => 'staf-laluan-panjang',
    'role' => 'staff',
], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
$csrfAlih = (string) $alih['csrf'];
$addedAdmin = $gate->handle('POST', 'ahli', $alih, [
    'name' => 'Ketua Admin',
    'email' => 'ketua@kedai.test',
    'password' => 'admin-laluan-panjang',
    'role' => 'admin',
], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
$csrfAlih = (string) $addedAdmin['body']['csrf'];
$asing = $open();
$madeAsing = $gate->handle('POST', 'daftar', $asing, [
    'name' => 'Pemilik Asing',
    'email' => 'asing@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Asing',
], ['x-csrf-token' => $asing['csrf']], '10.8.0.3', $host);
$anak = $open();
$anakLogin = $gate->handle('POST', 'masuk', $anak, [
    'email' => 'anak@kedai.test',
    'password' => 'staf-laluan-panjang',
], ['x-csrf-token' => $anak['csrf']], '10.8.0.4', $host);
$ketua = $open();
$ketuaLogin = $gate->handle('POST', 'masuk', $ketua, [
    'email' => 'ketua@kedai.test',
    'password' => 'admin-laluan-panjang',
], ['x-csrf-token' => $ketua['csrf']], '10.8.0.5', $host);
$anon = $open();
$deny('anonymous transfer is rejected', function () use ($gate, &$anon, $host): void {
    $gate->handle('POST', 'status', $anon, ['kind' => 'begin_transfer', 'next_status' => 'provisioning'], ['x-csrf-token' => $anon['csrf']], '10.8.0.1', $host);
}, 401);
$deny('transfer without csrf is rejected', function () use ($gate, &$alih, $host): void {
    $gate->handle('POST', 'status', $alih, ['kind' => 'begin_transfer'], [], '10.8.0.2', $host);
}, 403);
$deny('staff cannot open a transfer', function () use ($gate, &$anak, $anakLogin, $alih, $host): void {
    $gate->handle('POST', 'status', $anak, ['kind' => 'begin_transfer', 'business_id' => (int) $alih['business_id']], ['x-csrf-token' => $anakLogin['body']['csrf']], '10.8.0.4', $host);
}, 403);
$deny('admin cannot open a transfer', function () use ($gate, &$ketua, $ketuaLogin, $asing, $host): void {
    $gate->handle('POST', 'status', $ketua, ['kind' => 'begin_transfer', 'business_id' => (int) $asing['business_id']], ['x-csrf-token' => $ketuaLogin['body']['csrf']], '10.8.0.5', $host);
}, 403);
$deny('finish before a transfer is rejected', function () use ($gate, &$alih, $csrfAlih, $host): void {
    $gate->handle('POST', 'status', $alih, ['kind' => 'finish_transfer', 'next_status' => 'active'], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
}, 403);
$deny('reassign before a transfer is rejected', function () use ($gate, &$alih, $csrfAlih, $asing, $host): void {
    $gate->handle('POST', 'status', $alih, ['kind' => 'reassign_owner', 'owner_email' => 'ketua@kedai.test', 'next_owner_user_id' => (int) $asing['user_id']], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
}, 403);
$stillActive = (string) $pdo->query('SELECT status FROM businesses WHERE id = ' . (int) $alih['business_id'])->fetchColumn();
$otherActive = (string) $pdo->query('SELECT status FROM businesses WHERE id = ' . (int) $asing['business_id'])->fetchColumn();
$check('rejected transfer leaves both tenants active', $stillActive === 'active' && $otherActive === 'active');

$opened = $gate->handle('POST', 'status', $alih, [
    'kind' => 'begin_transfer',
    'next_status' => 'suspended',
    'business_id' => (int) $asing['business_id'],
], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
$csrfAlih = (string) $opened['body']['csrf'];
$ownStatus = (string) $pdo->query('SELECT status FROM businesses WHERE id = ' . (int) $alih['business_id'])->fetchColumn();
$foreignStatus = (string) $pdo->query('SELECT status FROM businesses WHERE id = ' . (int) $asing['business_id'])->fetchColumn();
$check('forged business id opens only the session tenant', ($opened['body']['business']['status'] ?? '') === 'provisioning' && $ownStatus === 'provisioning' && $foreignStatus === 'active');
$deny('a second transfer open is rejected', function () use ($gate, &$alih, $csrfAlih, $host): void {
    $gate->handle('POST', 'status', $alih, ['kind' => 'begin_transfer'], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
}, 403);
$deny('cross tenant owner email is rejected', function () use ($gate, &$alih, $csrfAlih, $asing, $host): void {
    $gate->handle('POST', 'status', $alih, ['kind' => 'reassign_owner', 'owner_email' => 'asing@kedai.test', 'business_id' => (int) $asing['business_id']], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
}, 404);
$ownerBefore = (int) $pdo->query('SELECT owner_user_id FROM businesses WHERE id = ' . (int) $alih['business_id'])->fetchColumn();
$check('cross tenant email does not change the owner', $ownerBefore === (int) $alih['user_id']);
$movedGate = $gate->handle('POST', 'status', $alih, [
    'kind' => 'reassign_owner',
    'owner_email' => 'KETUA@kedai.test',
    'next_owner_user_id' => (int) $asing['user_id'],
    'business_id' => (int) $asing['business_id'],
], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
$csrfAlih = (string) $movedGate['body']['csrf'];
$ketuaId = (int) $pdo->query('SELECT id FROM users WHERE email = \'ketua@kedai.test\'')->fetchColumn();
$asingOwner = (int) $pdo->query('SELECT owner_user_id FROM businesses WHERE id = ' . (int) $asing['business_id'])->fetchColumn();
$alihOwner = (int) $pdo->query('SELECT owner_user_id FROM businesses WHERE id = ' . (int) $alih['business_id'])->fetchColumn();
$check('numeric owner id cannot select another tenant', $alihOwner === $ketuaId && $asingOwner === (int) $asing['user_id'] && ($movedGate['body']['user']['role'] ?? '') === 'admin');
$deny('former owner cannot suspend during transfer', function () use ($gate, &$alih, $csrfAlih, $host): void {
    $gate->handle('POST', 'status', $alih, ['kind' => 'suspend_or_resume', 'next_status' => 'suspended'], ['x-csrf-token' => $csrfAlih], '10.8.0.2', $host);
}, 403);
$ketuaAgain = $open();
$ketuaNow = $gate->handle('POST', 'masuk', $ketuaAgain, [
    'email' => 'ketua@kedai.test',
    'password' => 'admin-laluan-panjang',
], ['x-csrf-token' => $ketuaAgain['csrf']], '10.8.0.6', $host);
$deny('finish cannot return to provisioning', function () use ($gate, &$ketuaAgain, $ketuaNow, $host): void {
    $gate->handle('POST', 'status', $ketuaAgain, ['kind' => 'finish_transfer', 'next_status' => 'provisioning'], ['x-csrf-token' => $ketuaNow['body']['csrf']], '10.8.0.6', $host);
}, 403);
$deny('suspend during provisioning is rejected', function () use ($gate, &$ketuaAgain, $ketuaNow, $host): void {
    $gate->handle('POST', 'status', $ketuaAgain, ['kind' => 'suspend_or_resume', 'next_status' => 'suspended'], ['x-csrf-token' => $ketuaNow['body']['csrf']], '10.8.0.6', $host);
}, 403);
$closed = $gate->handle('POST', 'status', $ketuaAgain, [
    'kind' => 'finish_transfer',
    'next_status' => 'active',
], ['x-csrf-token' => $ketuaNow['body']['csrf']], '10.8.0.6', $host);
$check('valid finish restores the active tenant', ($closed['body']['business']['status'] ?? '') === 'active' && ($closed['body']['user']['role'] ?? '') === 'owner');

$pdo->prepare('DELETE FROM business_users WHERE business_id = ? AND user_id = ?')->execute([(int) $anak['business_id'], (int) $anak['user_id']]);
$stale = $gate->handle('GET', 'sesi', $anak, [], [], '10.8.0.4', $host);
$check('removed member session is cleared', array_key_exists('user', $stale['body']) && $stale['body']['user'] === null && !isset($anak['business_id']));
$deny('stale member cannot transfer', function () use ($gate, &$anak, $host): void {
    $gate->handle('POST', 'status', $anak, ['kind' => 'begin_transfer'], ['x-csrf-token' => (string) ($anak['csrf'] ?? '')], '10.8.0.4', $host);
}, 401);

$fold = new AuthHttp($pdo, new RateLimit($rateDir . '-fold', 3, 900));
$foldSession = [];
$foldIssued = $fold->handle('GET', 'sesi', $foldSession, [], [], '10.9.0.1', $host);
$fold->handle('POST', 'daftar', $foldSession, [
    'name' => 'Pemilik Fold',
    'email' => 'fold@kedai.test',
    'password' => 'kata-laluan-yang-panjang',
    'business' => 'Kedai Fold',
], ['x-csrf-token' => $foldIssued['body']['csrf']], '10.9.0.2', $host);
$variants = ['Fold@Kedai.TEST', ' fold@kedai.test ', 'FOLD@kedai.test'];
foreach ($variants as $variant) {
    $attempt = [];
    $token = $fold->handle('GET', 'sesi', $attempt, [], [], '10.9.0.9', $host);
    try {
        $fold->handle('POST', 'masuk', $attempt, ['email' => $variant, 'password' => 'salah-salah-salah'], ['x-csrf-token' => $token['body']['csrf']], '10.9.0.9', $host);
    } catch (AuthException) {
    }
}
$lockedVariant = [];
$lockedToken = $fold->handle('GET', 'sesi', $lockedVariant, [], [], '10.9.0.9', $host);
$folded = false;
try {
    $fold->handle('POST', 'masuk', $lockedVariant, ['email' => '  FOLD@KEDAI.TEST ', 'password' => 'kata-laluan-yang-panjang'], ['x-csrf-token' => $lockedToken['body']['csrf']], '10.9.0.9', $host);
} catch (AuthException $error) {
    $folded = $error->status() === 429;
}
$check('email case and spaces share one login bucket', $folded);
$otherAttempt = [];
$otherToken = $fold->handle('GET', 'sesi', $otherAttempt, [], [], '10.9.0.9', $host);
$separate = false;
try {
    $fold->handle('POST', 'masuk', $otherAttempt, ['email' => 'alih@kedai.test', 'password' => 'salah-salah-salah'], ['x-csrf-token' => $otherToken['body']['csrf']], '10.9.0.9', $host);
} catch (AuthException $error) {
    $separate = $error->status() === 401;
}
$check('a different account keeps its own login bucket', $separate);

function privateConfigChecks(callable $check): void
{
    $saved = [];
    foreach (['BESHARE_OS_DSN', 'BESHARE_OS_DB_USER', 'BESHARE_OS_DB_PASSWORD', 'BESHARE_OS_DB_CONFIG', 'BESHARE_OS_RATE_DIR'] as $name) {
        $current = getenv($name);
        $saved[$name] = $current === false ? null : $current;
        putenv($name);
    }
    $scratch = sys_get_temp_dir() . '/beshare-os-config-' . bin2hex(random_bytes(4));
    $privateRoot = dirname(__DIR__, 3) . '/private';
    $defaultDb = $privateRoot . '/beshare-os-db.php';
    $defaultRate = $privateRoot . '/rate';
    $madePrivate = !is_dir($privateRoot);
    $madeDb = !is_file($defaultDb);
    $madeRate = !is_dir($defaultRate);
    $publicRate = dirname(__DIR__, 2) . '/os/var/rate';
    $publicRateExisted = is_dir($publicRate);
    $secret = 'ujian-db-bukan-rahsia';
    try {
        $dbSource = (string) file_get_contents(dirname(__DIR__) . '/src/Db.php');
        $rateSource = (string) file_get_contents(dirname(__DIR__) . '/src/RateLimit.php');
        $repo = dirname(__DIR__, 2);
        $check(
            'private paths sit beside the repository',
            str_contains($dbSource, "dirname(__DIR__, 3) . '/private/beshare-os-db.php'")
                && str_contains($rateSource, "dirname(__DIR__, 3) . '/private/rate'")
                && $defaultDb === dirname($repo) . '/private/beshare-os-db.php'
                && $defaultRate === dirname($repo) . '/private/rate'
                && !str_starts_with($defaultDb, rtrim($repo, '/') . '/')
                && !str_contains($defaultDb, '/public_html/')
        );
        $missing = caughtConfig(static function (): void {
            Db::config();
        });
        $check('missing private database file fails closed', !is_file($defaultDb) && $missing === [503, 'Pangkalan data belum dikonfigurasi.']);
        $rateMissing = caughtConfig(static function (): void {
            RateLimit::resolveDirectory();
        });
        $check('missing private rate directory fails closed', !is_dir($defaultRate) && $rateMissing === [500, 'Had percubaan tidak dapat disediakan.'] && ($publicRateExisted || !is_dir($publicRate)));

        $parentWritable = is_dir($privateRoot) || (is_dir(dirname($privateRoot)) && is_writable(dirname($privateRoot)));
        if ($parentWritable) {
            if (!is_dir($privateRoot) && !mkdir($privateRoot, 0700, true) && !is_dir($privateRoot)) {
                $parentWritable = false;
            }
        }
        if ($parentWritable) {
            file_put_contents($defaultDb, "<?php\nreturn ['dsn' => 'mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4', 'user' => 'pemilik-fail', 'password' => " . var_export($secret, true) . "];\n");
            chmod($defaultDb, 0600);
            $fromDefault = Db::config();
            $check('private database file supplies dsn user and password', $fromDefault === [
                'dsn' => 'mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4',
                'user' => 'pemilik-fail',
                'password' => $secret,
            ]);
            if (!is_dir($defaultRate)) {
                mkdir($defaultRate, 0700);
            }
            $check('private rate directory is the default store', RateLimit::resolveDirectory() === realpath($defaultRate));
        }

        $overrideDir = $scratch . '-rate';
        mkdir($overrideDir, 0700);
        putenv('BESHARE_OS_RATE_DIR=' . $overrideDir);
        $check('rate directory environment variable wins', RateLimit::resolveDirectory() === $overrideDir);
        putenv('BESHARE_OS_RATE_DIR');

        $fileConfig = $scratch . '-db.php';
        file_put_contents($fileConfig, "<?php\nreturn ['dsn' => 'mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4', 'user' => 'pemilik-env', 'password' => 'lain'];\n");
        chmod($fileConfig, 0600);
        putenv('BESHARE_OS_DB_CONFIG=' . $fileConfig);
        $fromEnvPath = Db::config();
        $check('database config path wins over the private file', ($fromEnvPath['user'] ?? '') === 'pemilik-env' && ($fromEnvPath['dsn'] ?? '') !== '' && ($fromEnvPath['password'] ?? '') !== '' && !str_contains((string) $fromEnvPath['dsn'], 'password='));
        putenv('BESHARE_OS_DSN=mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4');
        putenv('BESHARE_OS_DB_USER=pemilik-pembolehubah');
        putenv('BESHARE_OS_DB_PASSWORD=pembolehubah');
        $fromEnv = Db::config();
        $check('database environment variables win over files', ($fromEnv['user'] ?? '') === 'pemilik-pembolehubah' && ($fromEnv['password'] ?? '') === 'pembolehubah');
        putenv('BESHARE_OS_DSN');
        putenv('BESHARE_OS_DB_USER');
        putenv('BESHARE_OS_DB_PASSWORD');
        putenv('BESHARE_OS_DB_CONFIG');

        $inside = dirname(__DIR__, 2) . '/os/var/db-config-test.php';
        file_put_contents($inside, "<?php\nreturn ['dsn' => 'mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4', 'user' => 'dalam', 'password' => 'dalam'];\n");
        putenv('BESHARE_OS_DB_CONFIG=' . $inside);
        $insideRepo = caughtConfig(static function (): void {
            Db::config();
        });
        $check('database file inside the repository is rejected', $insideRepo === [500, 'Konfigurasi pangkalan ditolak.']);
        putenv('BESHARE_OS_DB_CONFIG');
        unlink($inside);

        $public = $scratch . '/public_html';
        mkdir($public, 0700, true);
        $publicFile = $public . '/beshare-os-db.php';
        file_put_contents($publicFile, "<?php\nreturn ['dsn' => 'mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4', 'user' => 'awam', 'password' => 'awam'];\n");
        putenv('BESHARE_OS_DB_CONFIG=' . $publicFile);
        $publicDb = caughtConfig(static function (): void {
            Db::config();
        });
        putenv('BESHARE_OS_RATE_DIR=' . $public . '/rate');
        $publicRateResult = caughtConfig(static function (): void {
            RateLimit::resolveDirectory();
        });
        $check('public web root configuration is rejected', $publicDb === [500, 'Konfigurasi pangkalan ditolak.'] && $publicRateResult === [500, 'Had percubaan tidak dapat disediakan.'] && !is_dir($public . '/rate'));
        putenv('BESHARE_OS_DB_CONFIG');
        putenv('BESHARE_OS_RATE_DIR');

        file_put_contents($fileConfig, "<?php\necho " . var_export($secret, true) . ";\nreturn ['dsn' => 'mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4', 'user' => 'bocor', 'password' => " . var_export($secret, true) . "];\n");
        putenv('BESHARE_OS_DB_CONFIG=' . $fileConfig);
        $leaked = caughtConfig(static function (): void {
            Db::config();
        });
        $check('database file output is rejected without the secret', $leaked === [500, 'Konfigurasi pangkalan ditolak.'] && !str_contains($leaked[1], $secret));
        file_put_contents($fileConfig, "<?php\nreturn ['dsn' => '', 'user' => '', 'password' => ''];\n");
        $invalid = caughtConfig(static function (): void {
            Db::config();
        });
        $check('invalid database file fails closed', $invalid === [500, 'Konfigurasi pangkalan ditolak.']);
        putenv('BESHARE_OS_DSN=mysql:host=127.0.0.1;dbname=u879723783_beshare_os;charset=utf8mb4;password=terbocor');
        putenv('BESHARE_OS_DB_USER=pemilik');
        putenv('BESHARE_OS_DB_PASSWORD=pembolehubah');
        $embedded = caughtConfig(static function (): void {
            Db::connect();
        });
        $check('dsn password assignment is rejected', $embedded === [500, 'Konfigurasi pangkalan tidak sah.'] && !str_contains($embedded[1], 'terbocor'));

        $labPassword = is_file('/tmp/maria-lab.pw') ? trim((string) file_get_contents('/tmp/maria-lab.pw')) : '';
        putenv('BESHARE_OS_DSN=mysql:host=127.0.0.1;port=3307;dbname=mysql;charset=utf8mb4');
        putenv('BESHARE_OS_DB_USER=root');
        putenv('BESHARE_OS_DB_PASSWORD=' . $labPassword);
        $wrongDatabase = caughtConfig(static function (): void {
            Db::connect();
        });
        $check('database name validation still rejects another database', $wrongDatabase === [500, 'Pangkalan data ditolak.']);
    } finally {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $inside = dirname(__DIR__, 2) . '/os/var/db-config-test.php';
        if (is_file($inside)) {
            unlink($inside);
        }
        if ($madeDb && is_file($defaultDb)) {
            unlink($defaultDb);
        }
        if ($madeRate && is_dir($defaultRate)) {
            rmdir($defaultRate);
        }
        if ($madePrivate && is_dir($privateRoot)) {
            rmdir($privateRoot);
        }
        if (is_dir($scratch)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($scratch);
        }
        foreach ([$scratch . '-db.php'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $overrideDir = $scratch . '-rate';
        if (is_dir($overrideDir)) {
            rmdir($overrideDir);
        }
    }
}

/** @return array{0:int,1:string} */
function caughtConfig(callable $action): array
{
    try {
        $action();
    } catch (AuthException $error) {
        return [$error->status(), $error->getMessage()];
    } catch (Throwable) {
        return [0, ''];
    }
    return [0, ''];
}

function lab(): ?PDO
{
    $path = '/tmp/maria-lab.pw';
    if (!is_file($path)) {
        fwrite(STDOUT, "FAIL lab password file\n");
        return null;
    }
    $password = trim((string) file_get_contents($path));
    $dsn = 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
    $server = new PDO($dsn, 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $server->exec('DROP DATABASE IF EXISTS auth_lab');
    $server->exec('CREATE DATABASE auth_lab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE auth_lab');
    $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/0001_foundation.up.sql');
    foreach (SqlScript::statements($sql) as $statement) {
        $server->exec($statement);
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=auth_lab;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES'");
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

exit($failures === 0 ? 0 : 1);
