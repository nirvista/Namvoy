<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Middleware\CsrfMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Route registration. Groups:
 *   - default group: CSRF-protected (all browser-facing + JSON API routes)
 *   - /api/webhooks: NO CsrfMiddleware - PSP signature verification instead (Step 5)
 *
 * Every protected handler calls Auth::requireRole([...]) as its first line.
 */
return static function (App $app): void {
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/api/health', [HealthController::class, 'check']);

        // Auth
        $group->get('/api/auth/csrf', [AuthController::class, 'csrf']);
        $group->post('/api/auth/register', [AuthController::class, 'register']);
        $group->post('/api/auth/login', [AuthController::class, 'login']);
        $group->post('/api/auth/logout', [AuthController::class, 'logout']);
        $group->get('/api/auth/me', [AuthController::class, 'me']);

        // Step 2+: partner onboarding, admin queues, catalog, bookings, ...
    })->add(new CsrfMiddleware());

    // Step 5: PSP webhooks - signature verified, no CSRF.
    // $app->group('/api/webhooks', function (RouteCollectorProxy $group): void { ... });
};
