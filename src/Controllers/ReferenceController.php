<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Public reference data used by forms and the catalog. */
final class ReferenceController extends Controller
{
    /** GET /api/destinations */
    public function destinations(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'destinations' => Database::fetchAll('SELECT id, slug, name, description, hero_image_url FROM destinations ORDER BY name'),
        ]);
    }

    /** GET /api/categories */
    public function categories(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'categories' => Database::fetchAll('SELECT id, slug, name, icon FROM categories ORDER BY name'),
        ]);
    }
}
