<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Csrf;
use App\Exceptions\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects mutating requests (POST/PUT/PATCH/DELETE) without a valid CSRF token,
 * read from the `_csrf` body field or the `X-CSRF-Token` header.
 *
 * Webhook routes (PSP signature verified instead) must be registered in a group
 * that does NOT include this middleware.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $token = is_array($body) ? ($body[Csrf::FIELD] ?? null) : null;
        if ($token === null) {
            $token = $request->getHeaderLine(Csrf::HEADER) ?: null;
        }

        if (!Csrf::verify(is_string($token) ? $token : null)) {
            throw new ForbiddenException('Invalid or missing CSRF token');
        }

        return $handler->handle($request);
    }
}
