<?php
declare(strict_types=1);

(function () {
    $envFile = __DIR__ . '/../.env';
    if (!is_readable($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key   = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $_ENV[$key] = $value;
        }
    }
})();

// HRD / Training mailer (cscro8.imis@gmail.com)
if (!defined('SMTP_HOST'))      define('SMTP_HOST',      $_ENV['SMTP_HOST']      ?? 'smtp.gmail.com');
if (!defined('SMTP_USERNAME'))  define('SMTP_USERNAME',  $_ENV['SMTP_USERNAME']  ?? '');
if (!defined('SMTP_PASSWORD'))  define('SMTP_PASSWORD',  $_ENV['SMTP_PASSWORD']  ?? '');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      (int)($_ENV['SMTP_PORT'] ?? 587));
if (!defined('MAIL_FROM'))      define('MAIL_FROM',      $_ENV['MAIL_FROM']      ?? '');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME'] ?? 'CSC RO VIII - Human Resource Division');

// IMIS system mailer (cscro8webhost@gmail.com) — password resets
if (!defined('SMTP_IMIS_HOST'))      define('SMTP_IMIS_HOST',      $_ENV['SMTP_IMIS_HOST']      ?? 'smtp.gmail.com');
if (!defined('SMTP_IMIS_USERNAME'))  define('SMTP_IMIS_USERNAME',  $_ENV['SMTP_IMIS_USERNAME']  ?? '');
if (!defined('SMTP_IMIS_PASSWORD'))  define('SMTP_IMIS_PASSWORD',  $_ENV['SMTP_IMIS_PASSWORD']  ?? '');
if (!defined('SMTP_IMIS_PORT'))      define('SMTP_IMIS_PORT',      (int)($_ENV['SMTP_IMIS_PORT'] ?? 587));
if (!defined('MAIL_IMIS_FROM'))      define('MAIL_IMIS_FROM',      $_ENV['MAIL_IMIS_FROM']      ?? '');
if (!defined('MAIL_IMIS_FROM_NAME')) define('MAIL_IMIS_FROM_NAME', $_ENV['MAIL_IMIS_FROM_NAME'] ?? 'CSC RO VIII Integrated Management Information System');
