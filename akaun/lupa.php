<?php
declare(strict_types=1);
use BesharOs\{AccountBridge,RateLimit};
require dirname(__DIR__).'/os/src/bootstrap.php';
require_once dirname(__DIR__).'/os/src/AccountBridge.php';
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD']??'') !== 'POST' || !str_starts_with($_SERVER['CONTENT_TYPE']??'', 'application/json')) { http_response_code(405); exit; }
$data = json_decode((string)file_get_contents('php://input',false,null,0,2048),true);
$email = strtolower(trim(is_string($data['email']??null) ? $data['email'] : ''));
try {
    $ip = new RateLimit(RateLimit::resolveDirectory(), 8, 900);
    $recipient = new RateLimit(RateLimit::resolveDirectory(), 2, 900);
    if ($ip->consume('shared-reset-ip|'.($_SERVER['REMOTE_ADDR']??'')) && filter_var($email,FILTER_VALIDATE_EMAIL)
        && $recipient->consume('shared-reset-email|'.$email)) {
        $reply = AccountBridge::call(['operation'=>'recover','email'=>$email]);
        if (($reply['email']??null) === $email && preg_match('/\A[a-f0-9]{64}\z/', $reply['token']??'')) {
            $url = 'https://beshareaisolution.com/akaun/pulih.html#'.$reply['token'];
            if (!mail($email,'Tetapkan semula kata laluan BeShare', "Buka pautan ini dalam masa 30 minit untuk memilih kata laluan baharu.\r\n\r\n".$url."\r\n\r\nAbaikan e-mel ini jika anda tidak meminta pautan.", ['From'=>'BeShare <noreply@beshareaisolution.com>'])) {
                error_log('BeShare recovery mail transport unavailable');
            }
        }
    }
    echo '{"ok":true}';
} catch (Throwable) { http_response_code(503); echo '{"ok":false}'; }
