<?php
// includes/send_email_async.php
require_once __DIR__ . '/config.php';
require_once ROOT_PATH . 'includes/mailer.php';

if ($argc < 3) {
    exit(1);
}

$function = $argv[1];
$params = json_decode($argv[2], true);

if (function_exists($function) && is_array($params)) {
    try {
        call_user_func_array($function, $params);
    } catch (Exception $e) {
        error_log("Async email error: " . $e->getMessage());
    }
}
?>