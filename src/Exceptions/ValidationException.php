<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string, string> $errors */
    public function __construct(private array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
