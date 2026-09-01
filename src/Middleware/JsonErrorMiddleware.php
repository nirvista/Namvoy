<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException as SlimHttpException;
use Slim\Psr7\Response;
use Throwable;

/**
 * Converts exceptions into JSON error responses. Stack traces are only included
 * when APP_DEBUG=true.
 */
final class JsonErrorMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
            return $this->json($e->getStatus(), ['error' => $e->getMessage(), 'errors' => $e->getErrors()]);
        } catch (HttpException $e) {
            return $this->json($e->getStatus(), ['error' => $e->getMessage()]);
        } catch (SlimHttpException $e) {
            return $this->json($e->getCode(), ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            $payload = ['error' => 'Internal server error'];
            if (env('APP_DEBUG', 'false') === 'true') {
                $payload['debug'] = ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
            }
            return $this->json(500, $payload);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(int $status, array $payload): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
