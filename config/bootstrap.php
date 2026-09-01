<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the web front controller and CLI scripts:
 * autoload, .env, error reporting. Returns nothing; use App\* classes after.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER'])->notEmpty();

if (env('APP_DEBUG', 'false') === 'true') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

date_default_timezone_set('UTC');
