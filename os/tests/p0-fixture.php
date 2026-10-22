<?php
declare(strict_types=1);
// CLI fixture only; invoked inside the disposable, socket-only test instance.
if (PHP_SAPI !== 'cli' || getenv('BESHARE_P0_LAB') !== 'disposable') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/SqlScript.php';
$dsn = (string) getenv('BESHARE_OS_DSN');
if (!str_starts_with($dsn, 'mysql:unix_socket=')) {
    exit(1);
}
$pdo = new PDO($dsn, 'root', 'synthetic-unused', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    foreach (['0001_foundation', '0002_coexistence_sync', '0003_coexistence_worker'] as $migration) {
        foreach (BesharOs\SqlScript::statements((string) file_get_contents(dirname(__DIR__) . '/migrations/' . $migration . '.up.sql')) as $sql) {
            $pdo->exec($sql);
        }
    }
    $a = BesharOs\Accounts::register($pdo, 'Owner A', 'a@example.test', 'synthetic-password-only', 'Business A');
    $b = BesharOs\Accounts::register($pdo, 'Owner B', 'b@example.test', 'synthetic-password-only', 'Business B');
    foreach (['admin', 'staff', 'disabled'] as $role) {
        $stmt = $pdo->prepare("INSERT INTO users (email,password_hash,name,status,created_at,updated_at) VALUES (?,? ,?, ?,NOW(),NOW())");
        $stmt->execute([$role . '@example.test', BesharOs\Password::hash('synthetic-password-only'), $role, $role === 'disabled' ? 'disabled' : 'active']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO business_users (business_id,user_id,role,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())')->execute([$a['business_id'], $id, $role === 'disabled' ? 'admin' : $role]);
    }
    $pdo->prepare("INSERT INTO whatsapp_connections (business_id,waba_id,phone_number_id,token_state,created_at,updated_at) VALUES (?,'100000000001','1443799975474056','cleared',NOW(),NOW())")->execute([$a['business_id']]);
    echo json_encode(['business_a' => $a['business_id'], 'business_b' => $b['business_id']]);
} elseif ($mode === 'session') {
    $role = $argv[2];
    $row = $pdo->query("SELECT u.id, bu.business_id FROM users u JOIN business_users bu ON bu.user_id=u.id WHERE u.email=" . $pdo->quote($role . '@example.test'))->fetch();
    session_name(BesharOs\SessionCookie::NAME);
    session_start();
    $_SESSION = ['user_id' => (int) $row['id'], 'business_id' => (int) $row['business_id']];
    // Deliberately forge session role: the authorization must still use the DB.
    $_SESSION['role'] = 'owner';
    echo json_encode(['cookie' => session_name() . '=' . session_id(), 'csrf' => BesharOs\Csrf::issue($_SESSION)]);
    session_write_close();
} elseif ($mode === 'disable-admin') {
    $pdo->exec("UPDATE users SET status='disabled' WHERE email='admin@example.test'");
} elseif ($mode === 'suspend') {
    $pdo->exec("UPDATE businesses SET status='suspended' WHERE id=1");
} elseif ($mode === 'events') {
    echo json_encode($pdo->query('SELECT business_id,connection_id,event_kind FROM webhook_events ORDER BY id')->fetchAll());
} elseif ($mode === 'snapshot') {
    $snapshot = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $snapshot[$table] = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll();
    }
    echo hash('sha256', serialize($snapshot));
} elseif ($mode === 'transport') {
    require dirname(__DIR__, 2) . '/whatsapp/meja/lib.php';
    if (hantar_whatsapp('synthetic', 'synthetic', '60120000000', 'test') !== false) { exit(1); }
    echo 'OFF';
} else {
    exit(1);
}
