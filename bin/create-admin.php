#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Create an admin user. Admins can never be created through the web app.
 *
 * Usage: php bin/create-admin.php <email> [full name]
 * The password is read from stdin (no echo) or the ADMIN_PASSWORD env var.
 */

use App\Auth;
use App\Database;

require __DIR__ . '/../config/bootstrap.php';

$email = strtolower(trim($argv[1] ?? ''));
$fullName = trim($argv[2] ?? '') ?: null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <email> [full name]\n");
    exit(1);
}

$password = getenv('ADMIN_PASSWORD') ?: '';
if ($password === '') {
    echo 'Password: ';
    shell_exec('stty -echo 2>/dev/null');
    $password = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    echo "\n";
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Admin password must be at least 12 characters.\n");
    exit(1);
}

if (Database::fetchOne('SELECT 1 FROM users WHERE email = ? LIMIT 1', 's', [$email]) !== null) {
    fwrite(STDERR, "A user with that email already exists.\n");
    exit(1);
}

$id = Auth::register($email, $password, $fullName, 'admin');
echo "Admin created: {$email} ({$id})\n";
