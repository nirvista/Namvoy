<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Exceptions\ForbiddenException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Experiences;
use App\Storage\LocalStorage;
use App\Storage\StorageInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Business portal: experiences owned by the authenticated operator.
 *
 * Every handler: (1) Auth::requireRole(['business']) first, (2) resolves the
 * caller's business_id from the session user, (3) puts `business_id = ?` in
 * the WHERE clause of every query that reads or mutates an experience.
 */
final class PartnerExperienceController extends Controller
{
    private const MAX_IMAGES = 10;
    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    private const IMAGE_MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Fields accepted on create/update and their bind types. */
    private const FIELDS = [
        'destination_id' => 's', 'category_id' => 's', 'title' => 's', 'description' => 's',
        'duration_minutes' => 'i', 'max_group_size' => 'i', 'price_amount' => 's', 'price_currency' => 's',
        'languages' => 's', 'included_items' => 's', 'cancellation_policy' => 's',
    ];

    private StorageInterface $publicStorage;

    public function __construct(?StorageInterface $publicStorage = null)
    {
        $this->publicStorage = $publicStorage ?? new LocalStorage(dirname(__DIR__, 2) . '/public/uploads');
    }

    /** GET /api/partner/experiences */
    public function index(Request $request, Response $response): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);

        return $this->json($response, ['experiences' => Experiences::fetchAll('e.business_id = ?', 's', [$businessId])]);
    }

    /** GET /api/partner/experiences/{id} */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);

        $exp = Experiences::fetchOne('e.id = ? AND e.business_id = ?', 'ss', [$args['id'], $businessId])
            ?? throw new HttpNotFoundException($request, 'Experience not found');

        return $this->json($response, ['experience' => $exp]);
    }

    /**
     * POST /api/partner/experiences - only approved businesses; saved as pending_review.
     */
    public function create(Request $request, Response $response): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id'], requireApproved: true);

        $data = $this->validate($this->body($request), partial: false);

        $id = uuid4();
        $slug = Experiences::makeSlug($data['title']);
        Database::execute(
            'INSERT INTO experiences (id, business_id, destination_id, category_id, title, slug, description, duration_minutes,
                                      max_group_size, price_amount, price_currency, languages, included_items, cancellation_policy, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'sssssssiissssss',
            [$id, $businessId, $data['destination_id'], $data['category_id'], $data['title'], $slug, $data['description'],
             $data['duration_minutes'], $data['max_group_size'], $data['price_amount'], $data['price_currency'],
             $data['languages'], $data['included_items'], $data['cancellation_policy'], 'pending_review']
        );

        return $this->json($response, ['experience' => Experiences::fetchOne('e.id = ? AND e.business_id = ?', 'ss', [$id, $businessId])], 201);
    }

    /**
     * PATCH /api/partner/experiences/{id}
     * Suspended experiences cannot be edited by the operator. Editing a published
     * experience sends it back to pending_review.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);

        $current = Database::fetchOne(
            'SELECT id, status FROM experiences WHERE id = ? AND business_id = ? LIMIT 1',
            'ss',
            [$args['id'], $businessId]
        ) ?? throw new HttpNotFoundException($request, 'Experience not found');

        if ($current['status'] === 'suspended') {
            throw new HttpException('Suspended experiences cannot be edited; contact support', 409);
        }

        $data = $this->validate($this->body($request), partial: true);
        if ($data === []) {
            throw new ValidationException(['body' => 'No editable fields supplied']);
        }

        $set = [];
        $types = '';
        $params = [];
        foreach ($data as $field => $value) {
            $set[] = "{$field} = ?";
            $types .= self::FIELDS[$field];
            $params[] = $value;
        }
        $set[] = 'status = ?';
        $types .= 's';
        $params[] = $current['status'] === 'draft' ? 'draft' : 'pending_review';

        $types .= 'ss';
        $params[] = $args['id'];
        $params[] = $businessId;

        Database::execute('UPDATE experiences SET ' . implode(', ', $set) . ' WHERE id = ? AND business_id = ?', $types, $params);

        return $this->json($response, ['experience' => Experiences::fetchOne('e.id = ? AND e.business_id = ?', 'ss', [$args['id'], $businessId])]);
    }

    /** POST /api/partner/experiences/{id}/submit - draft -> pending_review */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id'], requireApproved: true);

        $affected = Database::execute(
            "UPDATE experiences SET status = 'pending_review' WHERE id = ? AND business_id = ? AND status = 'draft'",
            'ss',
            [$args['id'], $businessId]
        );
        if ($affected === 0) {
            $exists = Database::fetchOne('SELECT status FROM experiences WHERE id = ? AND business_id = ?', 'ss', [$args['id'], $businessId]);
            if ($exists === null) {
                throw new HttpNotFoundException($request, 'Experience not found');
            }
            throw new HttpException("Only draft experiences can be submitted (current: {$exists['status']})", 409);
        }

        return $this->json($response, ['experience' => Experiences::fetchOne('e.id = ? AND e.business_id = ?', 'ss', [$args['id'], $businessId])]);
    }

    /** GET /api/partner/experiences/{id}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function availability(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);
        $this->assertOwned($request, $args['id'], $businessId);

        $q = $request->getQueryParams();
        $from = $this->parseDate($q['from'] ?? null) ?? date('Y-m-d');
        $to = $this->parseDate($q['to'] ?? null) ?? date('Y-m-d', strtotime($from . ' +90 days'));

        $rows = Database::fetchAll(
            'SELECT a.date, a.slots_total, a.slots_booked FROM experience_availability a
             JOIN experiences e ON e.id = a.experience_id
             WHERE a.experience_id = ? AND e.business_id = ? AND a.date BETWEEN ? AND ?
             ORDER BY a.date ASC',
            'ssss',
            [$args['id'], $businessId, $from, $to]
        );

        return $this->json($response, ['availability' => array_map(static fn (array $r): array => [
            'date' => $r['date'],
            'slots_total' => (int) $r['slots_total'],
            'slots_booked' => (int) $r['slots_booked'],
        ], $rows)]);
    }

    /**
     * PUT /api/partner/experiences/{id}/availability
     * body: {"dates": [{"date": "2026-10-01", "slots_total": 8}, ...]}
     * Upserts each date. slots_total = 0 closes a date; it can never drop below slots_booked.
     */
    public function setAvailability(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);
        $this->assertOwned($request, $args['id'], $businessId);

        $dates = $this->body($request)['dates'] ?? null;
        if (!is_array($dates) || $dates === [] || count($dates) > 366) {
            throw new ValidationException(['dates' => 'Provide 1-366 entries of {date, slots_total}']);
        }

        $errors = [];
        $clean = [];
        $today = date('Y-m-d');
        foreach (array_values($dates) as $i => $entry) {
            $date = $this->parseDate(is_array($entry) ? ($entry['date'] ?? null) : null);
            $slots = is_array($entry) ? ($entry['slots_total'] ?? null) : null;
            if ($date === null || $date < $today) {
                $errors["dates.{$i}.date"] = 'Must be a valid YYYY-MM-DD date, today or later';
            }
            if (!is_numeric($slots) || (int) $slots < 0 || (int) $slots > 1000 || (string) (int) $slots !== (string) $slots) {
                $errors["dates.{$i}.slots_total"] = 'Must be an integer between 0 and 1000';
            }
            if ($date !== null) {
                $clean[$date] = (int) $slots;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        Database::transaction(function () use ($args, $businessId, $clean, &$errors): void {
            foreach ($clean as $date => $slots) {
                $existing = Database::fetchOne(
                    'SELECT a.id, a.slots_booked FROM experience_availability a
                     JOIN experiences e ON e.id = a.experience_id
                     WHERE a.experience_id = ? AND e.business_id = ? AND a.date = ? FOR UPDATE',
                    'sss',
                    [$args['id'], $businessId, $date]
                );
                if ($existing !== null && $slots < (int) $existing['slots_booked']) {
                    $errors["dates.{$date}"] = "slots_total cannot be below slots_booked ({$existing['slots_booked']})";
                    continue;
                }
                if ($existing === null) {
                    Database::execute(
                        'INSERT INTO experience_availability (id, experience_id, date, slots_total) VALUES (?, ?, ?, ?)',
                        'sssi',
                        [uuid4(), $args['id'], $date, $slots]
                    );
                } else {
                    Database::execute('UPDATE experience_availability SET slots_total = ? WHERE id = ?', 'is', [$slots, $existing['id']]);
                }
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
        });

        return $this->availability($request->withQueryParams(['from' => min(array_keys($clean)), 'to' => max(array_keys($clean))]), $response, $args);
    }

    /** POST /api/partner/experiences/{id}/images  (multipart: image) */
    public function addImage(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);
        $this->assertOwned($request, $args['id'], $businessId);

        $file = $request->getUploadedFiles()['image'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'An image file is required']);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::IMAGE_MAX_BYTES) {
            throw new ValidationException(['image' => 'Image must be between 1 byte and 5 MB']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) $file->getStream()->read(8192)) ?: '';
        $file->getStream()->rewind();
        if (!isset(self::IMAGE_MIME_EXT[$mime])) {
            throw new ValidationException(['image' => 'Only JPEG, PNG and WebP images are accepted']);
        }

        $count = Database::fetchOne('SELECT COUNT(*) AS n FROM experience_images WHERE experience_id = ?', 's', [$args['id']]);
        if ((int) ($count['n'] ?? 0) >= self::MAX_IMAGES) {
            throw new ValidationException(['image' => 'Maximum of ' . self::MAX_IMAGES . ' images per experience']);
        }

        $imageId = uuid4();
        $key = sprintf('experiences/%s/%s.%s', $args['id'], $imageId, self::IMAGE_MIME_EXT[$mime]);
        $this->publicStorage->put($file, $key);

        try {
            Database::execute(
                'INSERT INTO experience_images (id, experience_id, image_url, display_order)
                 SELECT ?, e.id, ?, COALESCE((SELECT MAX(display_order) + 1 FROM experience_images WHERE experience_id = e.id), 0)
                 FROM experiences e WHERE e.id = ? AND e.business_id = ?',
                'ssss',
                [$imageId, '/uploads/' . $key, $args['id'], $businessId]
            );
        } catch (\Throwable $e) {
            $this->publicStorage->delete($key);
            throw $e;
        }

        return $this->json($response, ['experience' => Experiences::fetchOne('e.id = ? AND e.business_id = ?', 'ss', [$args['id'], $businessId])], 201);
    }

    /** DELETE /api/partner/experiences/{id}/images/{imageId} */
    public function removeImage(Request $request, Response $response, array $args): Response
    {
        $user = Auth::requireRole(['business']);
        $businessId = $this->businessId($user['id']);

        $img = Database::fetchOne(
            'SELECT i.id, i.image_url FROM experience_images i
             JOIN experiences e ON e.id = i.experience_id
             WHERE i.id = ? AND i.experience_id = ? AND e.business_id = ? LIMIT 1',
            'sss',
            [$args['imageId'], $args['id'], $businessId]
        ) ?? throw new HttpNotFoundException($request, 'Image not found');

        Database::execute('DELETE FROM experience_images WHERE id = ?', 's', [$img['id']]);
        if (str_starts_with((string) $img['image_url'], '/uploads/')) {
            $this->publicStorage->delete(substr((string) $img['image_url'], strlen('/uploads/')));
        }

        return $response->withStatus(204);
    }

    // ---------------------------------------------------------------------

    /**
     * Resolve the caller's business id. 403 if the account has no business or
     * (when required) is not yet approved.
     */
    private function businessId(string $userId, bool $requireApproved = false): string
    {
        $row = Database::fetchOne(
            'SELECT id, verification_status FROM businesses WHERE owner_user_id = ? LIMIT 1',
            's',
            [$userId]
        );
        if ($row === null) {
            throw new ForbiddenException('No business is linked to this account');
        }
        if ($requireApproved && $row['verification_status'] !== 'approved') {
            throw new ForbiddenException("Business verification is {$row['verification_status']}; experiences can only be created once approved");
        }
        return $row['id'];
    }

    private function assertOwned(Request $request, string $experienceId, string $businessId): void
    {
        $row = Database::fetchOne('SELECT 1 FROM experiences WHERE id = ? AND business_id = ? LIMIT 1', 'ss', [$experienceId, $businessId]);
        if ($row === null) {
            throw new HttpNotFoundException($request, 'Experience not found');
        }
    }

    private function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }

    /**
     * Validate experience fields. Returns only the fields present (partial) or all
     * fields (create), with JSON columns encoded and ready to bind.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function validate(array $in, bool $partial): array
    {
        $errors = [];
        $out = [];
        $has = static fn (string $k): bool => array_key_exists($k, $in);

        if (!$partial || $has('title')) {
            $v = trim((string) ($in['title'] ?? ''));
            if (mb_strlen($v) < 3 || mb_strlen($v) > 255) {
                $errors['title'] = 'Title must be 3-255 characters';
            }
            $out['title'] = $v;
        }
        if (!$partial || $has('description')) {
            $v = trim((string) ($in['description'] ?? ''));
            if (mb_strlen($v) < 20 || mb_strlen($v) > 10000) {
                $errors['description'] = 'Description must be 20-10000 characters';
            }
            $out['description'] = $v;
        }
        if (!$partial || $has('duration_minutes')) {
            $v = $in['duration_minutes'] ?? null;
            if (!is_numeric($v) || (int) $v < 15 || (int) $v > 10080) {
                $errors['duration_minutes'] = 'Duration must be 15-10080 minutes';
            }
            $out['duration_minutes'] = (int) $v;
        }
        if (!$partial || $has('max_group_size')) {
            $v = $in['max_group_size'] ?? null;
            if (!is_numeric($v) || (int) $v < 1 || (int) $v > 500) {
                $errors['max_group_size'] = 'Max group size must be 1-500';
            }
            $out['max_group_size'] = (int) $v;
        }
        if (!$partial || $has('price_amount')) {
            $v = (string) ($in['price_amount'] ?? '');
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v) || (float) $v <= 0) {
                $errors['price_amount'] = 'Price must be a positive amount with at most 2 decimals';
            }
            $out['price_amount'] = number_format((float) $v, 2, '.', '');
        }
        if (!$partial || $has('price_currency')) {
            $v = strtoupper(trim((string) ($in['price_currency'] ?? 'USD')));
            if (!in_array($v, Experiences::CURRENCIES, true)) {
                $errors['price_currency'] = 'Currency must be one of: ' . implode(', ', Experiences::CURRENCIES);
            }
            $out['price_currency'] = $v;
        }
        $refs = [
            'destination_id' => ['SELECT 1 FROM destinations WHERE id = ? LIMIT 1', 'Unknown destination'],
            'category_id' => ['SELECT 1 FROM categories WHERE id = ? LIMIT 1', 'Unknown category'],
        ];
        foreach ($refs as $field => [$sql, $message]) {
            if (!$partial || $has($field)) {
                $v = (string) ($in[$field] ?? '');
                if ($v === '' || Database::fetchOne($sql, 's', [$v]) === null) {
                    $errors[$field] = $message;
                }
                $out[$field] = $v;
            }
        }
        foreach (['languages' => 20, 'included_items' => 50] as $field => $max) {
            if (!$partial || $has($field)) {
                $v = $in[$field] ?? [];
                if (!is_array($v) || count($v) > $max || array_filter($v, static fn ($s): bool => !is_string($s) || trim($s) === '' || mb_strlen($s) > 100) !== []) {
                    $errors[$field] = "Must be a list of up to {$max} short strings";
                    $v = [];
                }
                $out[$field] = json_encode(array_values(array_map('trim', $v)), JSON_THROW_ON_ERROR);
            }
        }
        if (!$partial || $has('cancellation_policy')) {
            $v = trim((string) ($in['cancellation_policy'] ?? ''));
            if (mb_strlen($v) > 5000) {
                $errors['cancellation_policy'] = 'Cancellation policy too long (max 5000 chars)';
            }
            $out['cancellation_policy'] = $v !== '' ? $v : null;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }
}
