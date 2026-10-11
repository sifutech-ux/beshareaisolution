<?php
declare(strict_types=1);

use BesharOs\Accounts;
use BesharOs\AuthException;
use BesharOs\AuthHttp;
use BesharOs\Csrf;
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
