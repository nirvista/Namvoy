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
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;

final class PartnerController extends Controller
{
    public const LOCATIONS = ['da_nang', 'hoi_an'];

    private const DOC_MAX_FILES = 5;
    private const DOC_MAX_BYTES = 5 * 1024 * 1024;
    private const DOC_MIME_EXT = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private StorageInterface $storage;

    public function __construct(?StorageInterface $storage = null)
    {
        $this->storage = $storage ?? new LocalStorage();
    }

    /**
     * POST /api/partner/onboarding  (public, multipart/form-data)
     * Creates a 'business' user + a 'pending' business row in one transaction,
     * stores verification documents, and logs the new user in.
     */
    public function onboarding(Request $request, Response $response): Response
    {
        $body = $this->body($request);
        $files = $request->getUploadedFiles();

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? '')) ?: null;
        $businessName = trim((string) ($body['business_name'] ?? ''));
        $contactEmail = strtolower(trim((string) ($body['contact_email'] ?? ''))) ?: $email;
        $contactPhone = trim((string) ($body['contact_phone'] ?? '')) ?: null;
        $location = (string) ($body['location'] ?? '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }
        if ($businessName === '' || mb_strlen($businessName) > 255) {
            $errors['business_name'] = 'Business name is required (max 255 chars)';
        }
        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'A valid contact email is required';
        }
        if ($contactPhone !== null && mb_strlen($contactPhone) > 50) {
            $errors['contact_phone'] = 'Contact phone too long (max 50 chars)';
        }
        if (!in_array($location, self::LOCATIONS, true)) {
            $errors['location'] = 'Location must be one of: ' . implode(', ', self::LOCATIONS);
        }

        $docs = $this->collectDocs($files['verification_docs'] ?? [], $errors);

        if ($errors === []) {
            $exists = Database::fetchOne('SELECT 1 FROM users WHERE email = ? LIMIT 1', 's', [$email]);
            if ($exists !== null) {
                $errors['email'] = 'Email already registered';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $businessId = uuid4();
        $storedDocs = [];
        foreach ($docs as $doc) {
            $docId = uuid4();
            $key = sprintf('verification/%s/%s.%s', $businessId, $docId, self::DOC_MIME_EXT[$doc['mime']]);
            $this->storage->put($doc['file'], $key);
            $storedDocs[] = [
                'id' => $docId,
                'key' => $key,
                'original_name' => $doc['original_name'],
                'mime' => $doc['mime'],
                'size' => $doc['size'],
                'uploaded_at' => gmdate('c'),
            ];
        }

        try {
            $userId = Database::transaction(function () use ($email, $password, $fullName, $businessId, $businessName, $contactEmail, $contactPhone, $location, $storedDocs): string {
                $userId = Auth::register($email, $password, $fullName, 'business');
                Database::execute(
                    'INSERT INTO businesses (id, owner_user_id, business_name, contact_email, contact_phone, location, verification_status, verification_docs)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    'ssssssss',
                    [$businessId, $userId, $businessName, $contactEmail, $contactPhone, $location, 'pending', json_encode($storedDocs, JSON_THROW_ON_ERROR)]
                );
                return $userId;
            });
        } catch (\Throwable $e) {
            foreach ($storedDocs as $doc) {
                $this->storage->delete($doc['key']);
            }
            throw $e;
        }

        Auth::login(['id' => $userId, 'email' => $email, 'role' => 'business', 'full_name' => $fullName]);

        return $this->json($response, [
            'user' => Auth::user(),
            'business' => $this->findOwnBusiness($userId),
        ], 201);
    }

    /**
     * GET /api/partner/me - the authenticated operator's business profile.
     */
    public function me(Request $request, Response $response): Response
    {
        $user = Auth::requireRole(['business']);

        $business = $this->findOwnBusiness($user['id']);
        if ($business === null) {
            throw new HttpNotFoundException($request, 'No business found for this account');
        }

        return $this->json($response, ['business' => $business]);
    }

    /** @return array<string, mixed>|null */
    private function findOwnBusiness(string $ownerUserId): ?array
    {
        $row = Database::fetchOne(
            'SELECT id, business_name, contact_email, contact_phone, location, verification_status, verification_docs, created_at
             FROM businesses WHERE owner_user_id = ? LIMIT 1',
            's',
            [$ownerUserId]
        );
        if ($row === null) {
            return null;
        }
        $docs = json_decode((string) $row['verification_docs'], true) ?: [];
        $row['verification_docs'] = array_map(
            static fn (array $d): array => ['id' => $d['id'], 'original_name' => $d['original_name'], 'mime' => $d['mime'], 'size' => $d['size']],
            $docs
        );
        return $row;
    }

    /**
     * Validate uploaded verification documents (count, size, real MIME type).
     *
     * @param UploadedFileInterface|array<int, UploadedFileInterface> $uploaded
     * @param array<string, string> $errors
     * @return array<int, array{file: UploadedFileInterface, original_name: string, mime: string, size: int}>
     */
    private function collectDocs(UploadedFileInterface|array $uploaded, array &$errors): array
    {
        $list = $uploaded instanceof UploadedFileInterface ? [$uploaded] : array_values($uploaded);
        $list = array_filter($list, static fn ($f): bool => $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE);

        if ($list === []) {
            $errors['verification_docs'] = 'At least one verification document is required';
            return [];
        }
        if (count($list) > self::DOC_MAX_FILES) {
            $errors['verification_docs'] = 'At most ' . self::DOC_MAX_FILES . ' documents allowed';
            return [];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $docs = [];
        foreach ($list as $i => $file) {
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors["verification_docs.{$i}"] = 'Upload failed';
                continue;
            }
            $size = (int) $file->getSize();
            if ($size <= 0 || $size > self::DOC_MAX_BYTES) {
                $errors["verification_docs.{$i}"] = 'File must be between 1 byte and 5 MB';
                continue;
            }
            $mime = $finfo->buffer((string) $file->getStream()->read(8192)) ?: '';
            $file->getStream()->rewind();
            if (!isset(self::DOC_MIME_EXT[$mime])) {
                $errors["verification_docs.{$i}"] = 'Only PDF, JPEG and PNG files are accepted';
                continue;
            }
            $docs[] = [
                'file' => $file,
                'original_name' => mb_substr(basename((string) $file->getClientFilename()), 0, 255),
                'mime' => $mime,
                'size' => $size,
            ];
        }
        return $docs;
    }
}
