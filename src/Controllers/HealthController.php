<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController extends Controller
{
    public function check(Request $request, Response $response): Response
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS applied FROM schema_migrations');

        return $this->json($response, [
            'status' => 'ok',
            'migrations_applied' => (int) ($row['applied'] ?? 0),
        ]);
    }
}
