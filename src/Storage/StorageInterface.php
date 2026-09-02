<?php

declare(strict_types=1);

namespace App\Storage;

use Psr\Http\Message\UploadedFileInterface;

/**
 * File storage abstraction. Local disk now; an S3-compatible adapter (e.g.
 * Backblaze B2) can be added later without touching business logic.
 */
interface StorageInterface
{
    /**
     * Persist an uploaded file under $key (a relative path such as
     * "verification/<business_id>/<uuid>.pdf").
     */
    public function put(UploadedFileInterface $file, string $key): void;

    /** Open a read stream for the stored object. */
    public function readStream(string $key);

    public function exists(string $key): bool;

    public function delete(string $key): void;
}
