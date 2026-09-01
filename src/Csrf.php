<?php

declare(strict_types=1);

namespace App;

/**
 * Single CSRF token per session. Every mutating HTML form must include
 * csrf_field() and every mutating route must be covered by CsrfMiddleware
 * (or call Csrf::verify() explicitly).
 */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    public static function token(): string
    {
        Auth::startSession();
        if (empty($_SESSION[self::FIELD])) {
            $_SESSION[self::FIELD] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::FIELD];
    }

    public static function verify(?string $token): bool
    {
        Auth::startSession();
        $expected = $_SESSION[self::FIELD] ?? null;
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }
}
