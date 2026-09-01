<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController extends Controller
{
    /** Issue the session CSRF token for JS/API clients. */
    public function csrf(Request $request, Response $response): Response
    {
        return $this->json($response, ['csrf_token' => Csrf::token()]);
    }

    /** Self-registration always creates a 'traveler'. Business accounts come from partner onboarding. */
    public function register(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? '')) ?: null;

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }
        if ($errors === []) {
            $exists = Database::fetchOne('SELECT 1 FROM users WHERE email = ? LIMIT 1', 's', [$email]);
            if ($exists !== null) {
                $errors['email'] = 'Email already registered';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = Auth::register($email, $password, $fullName, 'traveler');
        Auth::login(['id' => $id, 'email' => $email, 'role' => 'traveler', 'full_name' => $fullName]);

        return $this->json($response, ['user' => Auth::user()], 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $user = Auth::attempt($email, $password);
        if ($user === null) {
            throw new UnauthorizedException('Invalid email or password');
        }

        Auth::login($user);

        return $this->json($response, ['user' => Auth::user()]);
    }

    public function logout(Request $request, Response $response): Response
    {
        Auth::logout();
        return $response->withStatus(204);
    }

    public function me(Request $request, Response $response): Response
    {
        $user = Auth::requireRole(Auth::ROLES);
        return $this->json($response, ['user' => $user]);
    }
}
