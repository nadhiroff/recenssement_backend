<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("config/config.php manquant : copier config.example.php.\n");
}
require_once $configFile;
date_default_timezone_set('UTC');
define('BASE_PATH', dirname(__DIR__));

ini_set('display_errors', APP_ENV === 'prod' ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php_errors.log');
error_reporting(E_ALL);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    } elseif (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $file = BASE_PATH . '/lib/PHPMailer/src/' . substr($class, 20) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
