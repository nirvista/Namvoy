<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Exceptions\ValidationException;
use App\Storage\LocalStorage;
use App\Storage\StorageInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

final class AdminBusinessController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    private StorageInterface $storage;

    public function __construct(?StorageInterface $storage = null)
    {
        $this->storage = $storage ?? new LocalStorage();
    }

    /**
     * GET /api/admin/businesses?status=pending  - approval queue (default: pending)
     */
    public function index(Request $request, Response $response): Response
    {
        Auth::requireRole(['admin']);

        $status = (string) ($request->getQueryParams()['status'] ?? 'pending');
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException(['status' => 'Status must be one of: ' . implode(', ', self::STATUSES)]);
        }

        $rows = Database::fetchAll(
            'SELECT b.id, b.business_name, b.contact_email, b.contact_phone, b.location, b.verification_status,
                    b.verification_docs, b.created_at, u.email AS owner_email, u.full_name AS owner_name
             FROM businesses b
             JOIN users u ON u.id = b.owner_user_id
             WHERE b.verification_status = ?
             ORDER BY b.created_at ASC',
            's',
            [$status]
        );

        return $this->json($response, ['businesses' => array_map([$this, 'present'], $rows)]);
    }

    /**
     * GET /api/admin/businesses/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        Auth::requireRole(['admin']);

        $row = $this->find($args['id']) ?? throw new HttpNotFoundException($request, 'Business not found');

        return $this->json($response, ['business' => $this->present($row)]);
    }

    /**
     * PATCH /api/admin/businesses/{id}   body: {"verification_status": "approved"|"rejected"}
     */
    public function review(Request $request, Response $response, array $args): Response
    {
        Auth::requireRole(['admin']);

        $status = (string) ($this->body($request)['verification_status'] ?? '');
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new ValidationException(['verification_status' => 'Must be "approved" or "rejected"']);
        }

        $affected = Database::execute(
            'UPDATE businesses SET verification_status = ? WHERE id = ?',
            'ss',
            [$status, $args['id']]
        );
        if ($affected === 0 && $this->find($args['id']) === null) {
            throw new HttpNotFoundException($request, 'Business not found');
        }

        return $this->json($response, ['business' => $this->present($this->find($args['id']))]);
    }

    /**
     * GET /api/admin/businesses/{id}/docs/{docId} - stream a verification document.
     */
    public function document(Request $request, Response $response, array $args): Response
    {
        Auth::requireRole(['admin']);

        $row = $this->find($args['id']) ?? throw new HttpNotFoundException($request, 'Business not found');

        $doc = null;
        foreach (json_decode((string) $row['verification_docs'], true) ?: [] as $d) {
            if ($d['id'] === $args['docId']) {
                $doc = $d;
                break;
            }
        }
        if ($doc === null || !$this->storage->exists($doc['key'])) {
            throw new HttpNotFoundException($request, 'Document not found');
        }

        return $response
            ->withHeader('Content-Type', $doc['mime'])
            ->withHeader('Content-Length', (string) $doc['size'])
            ->withHeader('Content-Disposition', 'inline; filename="' . str_replace(['"', "\r", "\n"], '', $doc['original_name']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody(new Stream($this->storage->readStream($doc['key'])));
    }

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        return Database::fetchOne(
            'SELECT b.id, b.business_name, b.contact_email, b.contact_phone, b.location, b.verification_status,
                    b.verification_docs, b.created_at, u.email AS owner_email, u.full_name AS owner_name
             FROM businesses b
             JOIN users u ON u.id = b.owner_user_id
             WHERE b.id = ? LIMIT 1',
            's',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $docs = json_decode((string) $row['verification_docs'], true) ?: [];
        $row['verification_docs'] = array_map(static fn (array $d): array => [
            'id' => $d['id'],
            'original_name' => $d['original_name'],
            'mime' => $d['mime'],
            'size' => $d['size'],
            'url' => sprintf('/api/admin/businesses/%s/docs/%s', $row['id'], $d['id']),
        ], $docs);
        return $row;
    }
}
