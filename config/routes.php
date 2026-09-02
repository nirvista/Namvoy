<?php

declare(strict_types=1);

use App\Controllers\AdminBusinessController;
use App\Controllers\AdminExperienceController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\PartnerController;
use App\Controllers\PartnerExperienceController;
use App\Controllers\ReferenceController;
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

        // Public reference data
        $group->get('/api/destinations', [ReferenceController::class, 'destinations']);
        $group->get('/api/categories', [ReferenceController::class, 'categories']);

        // Partner (business operators)
        $group->post('/api/partner/onboarding', [PartnerController::class, 'onboarding']);
        $group->get('/api/partner/me', [PartnerController::class, 'me']);
        $group->get('/api/partner/experiences', [PartnerExperienceController::class, 'index']);
        $group->post('/api/partner/experiences', [PartnerExperienceController::class, 'create']);
        $group->get('/api/partner/experiences/{id}', [PartnerExperienceController::class, 'show']);
        $group->patch('/api/partner/experiences/{id}', [PartnerExperienceController::class, 'update']);
        $group->post('/api/partner/experiences/{id}/submit', [PartnerExperienceController::class, 'submit']);
        $group->get('/api/partner/experiences/{id}/availability', [PartnerExperienceController::class, 'availability']);
        $group->put('/api/partner/experiences/{id}/availability', [PartnerExperienceController::class, 'setAvailability']);
        $group->post('/api/partner/experiences/{id}/images', [PartnerExperienceController::class, 'addImage']);
        $group->delete('/api/partner/experiences/{id}/images/{imageId}', [PartnerExperienceController::class, 'removeImage']);

        // Admin
        $group->get('/api/admin/businesses', [AdminBusinessController::class, 'index']);
        $group->get('/api/admin/businesses/{id}', [AdminBusinessController::class, 'show']);
        $group->patch('/api/admin/businesses/{id}', [AdminBusinessController::class, 'review']);
        $group->get('/api/admin/businesses/{id}/docs/{docId}', [AdminBusinessController::class, 'document']);
        $group->get('/api/admin/experiences', [AdminExperienceController::class, 'index']);
        $group->get('/api/admin/experiences/pending', [AdminExperienceController::class, 'pending']);
        $group->patch('/api/admin/experiences/{id}/approve', [AdminExperienceController::class, 'approve']);

        // Step 4+: public catalog, bookings, ...
    })->add(new CsrfMiddleware());

    // Step 5: PSP webhooks - signature verified, no CSRF.
    // $app->group('/api/webhooks', function (RouteCollectorProxy $group): void { ... });
};
