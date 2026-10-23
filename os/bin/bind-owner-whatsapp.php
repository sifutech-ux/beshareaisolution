<?php
declare(strict_types=1);

use BesharOs\Db;
use BesharOs\Membership;
use BesharOs\MetaGraph;
use BesharOs\OwnerBindingException;
use BesharOs\OwnerConnectionImporter;
use BesharOs\PrivateConfig;
use BesharOs\StreamMetaTransport;
use BesharOs\TokenSeal;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/OwnerConnectionImporter.php';
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    // Provider errors and credential paths must never reach command logs.
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    exit(ownerBindingMain($argv));
}

/** @param list<string> $argv */
function ownerBindingMain(array $argv): int
{
    try {
        $options = ownerBindingOptions($argv);
        if (isset($options['help'])) {
            fwrite(STDOUT, <<<'HELP'
Owner Cloud API import: preflight is read-only; --apply writes only the local OS database.

php os/bin/bind-owner-whatsapp.php [--business=ID --owner=ID --portfolio=ID --waba=ID --phone=ID --display=+NUMBER]
php os/bin/bind-owner-whatsapp.php [same options] --apply --fingerprint=PREFLIGHT_FINGERPRINT

An existing active phone blocks import by default. To review and locally replace exactly
one same-business connection, include --replace-connection=ID in BOTH commands.
Replacement clears that old local token and marks the old row disconnected. It does not
unsubscribe, delete, register, send a message, or change anything in Meta.

Defaults come from private owner_whatsapp_* configuration. An explicitly supplied value
must match its configured value. Credentials are accepted only from the private server
configuration or its explicitly configured, protected legacy JSON file; never CLI args.
Successful binding does not prove callback delivery, a successful send, or AI replies.

HELP);
            return 0;
        }
        $apply = isset($options['apply']);
        $fingerprint = $options['fingerprint'] ?? '';
        if ($apply && preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new OwnerBindingException('preflight_fingerprint_required');
        }
        if (!$apply && $fingerprint !== '') throw new OwnerBindingException('fingerprint_requires_apply');

        require dirname(__DIR__) . '/src/bootstrap.php';
        require dirname(__DIR__, 2) . '/whatsapp/sambungan/ids.php';
        $config = PrivateConfig::load();
        if (!is_array($config)) throw new OwnerBindingException('private_configuration_required');
        $businessId = ownerBindingId(ownerBindingSetting($options, $config, 'business', 'business_id'));
        $ownerId = ownerBindingId(ownerBindingSetting($options, $config, 'owner', 'user_id'));
        $expected = [
            'portfolio_id' => ownerBindingSetting($options, $config, 'portfolio', 'portfolio_id'),
            'waba_id' => ownerBindingSetting($options, $config, 'waba', 'waba_id'),
            'phone_number_id' => ownerBindingSetting($options, $config, 'phone', 'phone_number_id'),
            'display_phone' => ownerBindingSetting($options, $config, 'display', 'display_phone'),
        ];
        foreach (['portfolio_id', 'waba_id', 'phone_number_id'] as $key) {
            if (preg_match('/\A[1-9][0-9]{4,31}\z/D', $expected[$key]) !== 1) {
                throw new OwnerBindingException('expected_assets_invalid');
            }
        }
        OwnerConnectionImporter::normalizePhone($expected['display_phone']);
        if (isset($options['replace-connection'])) {
            $expected['replace_connection_id'] = ownerBindingId($options['replace-connection']);
        }
        $secret = $config['app_secret'] ?? '';
        $seal = TokenSeal::tryFrom($config);
        if ($secret === '' || strlen($secret) > 256 || preg_match('/[\x00-\x20\x7f]/', $secret) === 1 || !$seal instanceof TokenSeal) {
            throw new OwnerBindingException('server_credentials_unavailable');
        }
        $pdo = Db::connect();
        $actor = Membership::require($pdo, $ownerId, $businessId);
        if ($actor['role'] !== 'owner' || $actor['status'] !== 'active' || $actor['owner_user_id'] !== $ownerId) {
            throw new OwnerBindingException('owner_denied');
        }
        $token = ownerBindingToken($config, $expected);
        $graph = new MetaGraph(BESHARE_META_APP_ID, $secret, BESHARE_META_GRAPH, new StreamMetaTransport());
        $importer = new OwnerConnectionImporter($pdo, $graph, $seal, BESHARE_META_APP_ID);
        $result = $apply
            ? $importer->apply($actor, $token, $expected, $fingerprint)
            : $importer->inspect($actor, $token, $expected);
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return 0;
    } catch (OwnerBindingException $error) {
        fwrite(STDOUT, json_encode(['status' => 'blocked', 'reason' => $error->reason], JSON_THROW_ON_ERROR) . "\n");
        return 1;
    } catch (Throwable) {
        fwrite(STDOUT, "{\"status\":\"blocked\",\"reason\":\"owner_binding_unavailable\"}\n");
        return 1;
    }
}

/** @param list<string> $argv @return array<string,string> */
function ownerBindingOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument)) throw new OwnerBindingException('arguments_invalid');
        if ($argument === '--apply' || $argument === '--help') {
            $key = substr($argument, 2);
            $value = '1';
        } elseif (preg_match('/\A--(business|owner|portfolio|waba|phone|display|fingerprint|replace-connection)=(.{1,128})\z/D', $argument, $matches) === 1) {
            $key = $matches[1];
            $value = $matches[2];
        } else {
            // Unknown arguments (including accidental tokens) are never echoed.
            throw new OwnerBindingException('arguments_invalid');
        }
        if (isset($options[$key])) throw new OwnerBindingException('arguments_duplicate');
        $options[$key] = $value;
    }
    if (isset($options['help']) && count($options) !== 1) throw new OwnerBindingException('arguments_invalid');
    return $options;
}

/** @param array<string,string> $options @param array<string,string> $config */
function ownerBindingSetting(array $options, array $config, string $option, string $key): string
{
    $configured = $config['owner_whatsapp_' . $key] ?? '';
    $supplied = $options[$option] ?? '';
    if ($configured !== '' && $supplied !== '' && $configured !== $supplied) {
        if ($option !== 'display' || OwnerConnectionImporter::normalizePhone($configured) !== OwnerConnectionImporter::normalizePhone($supplied)) {
            throw new OwnerBindingException('configured_target_mismatch');
        }
    }
    $value = $supplied !== '' ? $supplied : $configured;
    if ($value === '') throw new OwnerBindingException('owner_target_required');
    return $value;
}

function ownerBindingId(string $value): int
{
    if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1 || (string) (int) $value !== $value) {
        throw new OwnerBindingException('local_identity_invalid');
    }
    return (int) $value;
}

/** @param array<string,string> $config @param array<string,mixed> $expected */
function ownerBindingToken(array $config, array $expected): string
{
    $direct = $config['owner_whatsapp_access_token'] ?? '';
    $path = $config['owner_whatsapp_legacy_token_path'] ?? '';
    if ($direct !== '' && $path !== '') throw new OwnerBindingException('credential_source_ambiguous');
    if ($direct !== '') return $direct;
    if ($path === '' || !str_starts_with($path, '/') || !function_exists('posix_geteuid')) {
        throw new OwnerBindingException('private_token_source_required');
    }
    $real = @realpath($path);
    $repo = realpath(dirname(__DIR__, 2));
    if ($real === false || $repo === false || $real !== $path || $real === $repo
        || str_starts_with($real, rtrim($repo, '/') . '/')
        || preg_match('#(?:^|/)public_html(?:/|$)#', $real) === 1) {
        throw new OwnerBindingException('legacy_token_path_denied');
    }
    $stat = @lstat($real);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
        || $stat['uid'] !== posix_geteuid() || $stat['size'] < 1 || $stat['size'] > 65536) {
        throw new OwnerBindingException('legacy_token_file_unprotected');
    }
    $handle = @fopen($real, 'rb');
    if (!is_resource($handle)) throw new OwnerBindingException('legacy_token_file_unreadable');
    try {
        $opened = fstat($handle);
        if (!is_array($opened) || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev']
            || $opened['uid'] !== $stat['uid'] || $opened['mode'] !== $stat['mode'] || $opened['size'] !== $stat['size']) {
            throw new OwnerBindingException('legacy_token_file_changed');
        }
        $raw = stream_get_contents($handle, 65537);
        $after = fstat($handle);
        if (!is_string($raw) || strlen($raw) !== $stat['size'] || !is_array($after)
            || $after['mtime'] !== $stat['mtime'] || $after['size'] !== $stat['size']) {
            throw new OwnerBindingException('legacy_token_file_changed');
        }
    } finally {
        fclose($handle);
    }
    try {
        $legacy = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (Throwable) {
        throw new OwnerBindingException('legacy_token_json_invalid');
    }
    $phoneId = is_array($legacy) ? ($legacy['phone_number_id'] ?? null) : null;
    if (!is_array($legacy) || (!is_string($phoneId) && !is_int($phoneId)) || (string) $phoneId !== $expected['phone_number_id']
        || OwnerConnectionImporter::normalizePhone($legacy['paparan'] ?? null) !== OwnerConnectionImporter::normalizePhone($expected['display_phone'])
        || !is_string($legacy['token_meta'] ?? null) || $legacy['token_meta'] === '') {
        throw new OwnerBindingException('legacy_token_target_mismatch');
    }
    return $legacy['token_meta'];
}
