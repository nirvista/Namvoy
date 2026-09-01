<?php

declare(strict_types=1);

use App\Database;

/**
 * Read an environment variable (loaded from .env by phpdotenv).
 */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

/**
 * Global shorthand for Database::queryPrepared() - the single entry point for
 * all SQL that includes user-supplied values.
 *
 * @param array<int, mixed> $params
 */
function queryPrepared(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    return Database::queryPrepared($conn, $sql, $types, $params);
}

/**
 * RFC 4122 version 4 UUID, generated from random_bytes().
 */
function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * HTML-escape for output in templates.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
