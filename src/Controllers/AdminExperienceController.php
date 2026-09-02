<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Exceptions\ValidationException;
use App\Experiences;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

final class AdminExperienceController extends Controller
{
    /** Review decision -> resulting experience status. */
    private const DECISIONS = [
        'publish' => 'published',
        'reject' => 'draft',
        'suspend' => 'suspended',
    ];

    /** GET /api/admin/experiences/pending */
    public function pending(Request $request, Response $response): Response
    {
        Auth::requireRole(['admin']);

        return $this->json($response, [
            'experiences' => Experiences::fetchAll('e.status = ?', 's', ['pending_review'], 'e.created_at ASC'),
        ]);
    }

    /** GET /api/admin/experiences?status=published */
    public function index(Request $request, Response $response): Response
    {
        Auth::requireRole(['admin']);

        $status = (string) ($request->getQueryParams()['status'] ?? 'pending_review');
        if (!in_array($status, Experiences::STATUSES, true)) {
            throw new ValidationException(['status' => 'Status must be one of: ' . implode(', ', Experiences::STATUSES)]);
        }

        return $this->json($response, ['experiences' => Experiences::fetchAll('e.status = ?', 's', [$status], 'e.created_at ASC')]);
    }

    /**
     * PATCH /api/admin/experiences/{id}/approve   body: {"decision": "publish"|"reject"|"suspend"}
     * reject returns the experience to the operator as a draft; suspend removes a
     * live listing and locks it from operator edits.
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        Auth::requireRole(['admin']);

        $decision = (string) ($this->body($request)['decision'] ?? '');
        if (!isset(self::DECISIONS[$decision])) {
            throw new ValidationException(['decision' => 'Must be one of: ' . implode(', ', array_keys(self::DECISIONS))]);
        }

        $affected = Database::execute(
            'UPDATE experiences SET status = ? WHERE id = ?',
            'ss',
            [self::DECISIONS[$decision], $args['id']]
        );
        $exp = Experiences::fetchOne('e.id = ?', 's', [$args['id']]);
        if ($affected === 0 && $exp === null) {
            throw new HttpNotFoundException($request, 'Experience not found');
        }

        return $this->json($response, ['experience' => $exp]);
    }
}
