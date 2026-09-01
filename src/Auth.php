<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\ForbiddenException;
use App\Exceptions\UnauthorizedException;

/**
 * Session-based authentication. requireRole() is the ONLY authorization
 * primitive in the codebase: every protected route handler calls it as its
 * first line. Never write an inline role check.
 */
final class Auth
{
    public const ROLES = ['traveler', 'business', 'admin'];

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(env('SESSION_NAME', 'namvoy_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => env('SESSION_SECURE', 'false') === 'true',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Enforce that the current session belongs to a user with one of $allowedRoles.
     * Throws UnauthorizedException (401) if not logged in, ForbiddenException (403)
     * if logged in with the wrong role. Returns the session user on success.
     *
     * @param string[] $allowedRoles
     * @return array{id: string, email: string, role: string, full_name: ?string}
     */
    public static function requireRole(array $allowedRoles): array
    {
        foreach ($allowedRoles as $role) {
            if (!in_array($role, self::ROLES, true)) {
                throw new \InvalidArgumentException("Unknown role: {$role}");
            }
        }

        $user = self::user();
        if ($user === null) {
            throw new UnauthorizedException();
        }
        if (!in_array($user['role'], $allowedRoles, true)) {
            throw new ForbiddenException();
        }

        return $user;
    }

    /**
     * @return array{id: string, email: string, role: string, full_name: ?string}|null
     */
    public static function user(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * @param array{id: string, email: string, role: string, full_name: ?string} $user
     */
    public static function login(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'full_name' => $user['full_name'] ?? null,
        ];
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }

    /**
     * Verify credentials against the users table. Returns the user row (without
     * password_hash) or null.
     *
     * @return array{id: string, email: string, role: string, full_name: ?string}|null
     */
    public static function attempt(string $email, string $password): ?array
    {
        $row = Database::fetchOne(
            'SELECT id, email, password_hash, full_name, role FROM users WHERE email = ? LIMIT 1',
            's',
            [$email]
        );
        if ($row === null || !password_verify($password, $row['password_hash'])) {
            return null;
        }
        unset($row['password_hash']);
        return $row;
    }

    /**
     * Create a user. Only 'traveler' may be self-registered; 'business' is created
     * via partner onboarding (Step 2) and 'admin' via the CLI (bin/create-admin.php).
     */
    public static function register(string $email, string $password, ?string $fullName, string $role = 'traveler'): string
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown role: {$role}");
        }

        $id = uuid4();
        Database::execute(
            'INSERT INTO users (id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)',
            'sssss',
            [$id, $email, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]
        );
        return $id;
    }
}
