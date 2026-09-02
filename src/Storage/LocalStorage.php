<?php

declare(strict_types=1);

namespace App\Storage;

use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Stores files under storage/uploads (outside the web root, so verification
 * documents are never directly URL-addressable).
 */
final class LocalStorage implements StorageInterface
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? env('STORAGE_PATH', dirname(__DIR__, 2) . '/storage/uploads'), '/');
    }

    public function put(UploadedFileInterface $file, string $key): void
    {
        $target = $this->path($key);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create storage directory: {$dir}");
        }
        $file->moveTo($target);
    }

    public function readStream(string $key)
    {
        $stream = fopen($this->path($key), 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot open stored file: {$key}");
        }
        return $stream;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** Resolve a key to an absolute path, refusing anything that escapes the root. */
    private function path(string $key): string
    {
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new InvalidArgumentException('Invalid storage key');
        }
        return $this->root . '/' . $key;
    }
}
