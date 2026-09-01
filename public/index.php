<?php

declare(strict_types=1);

use App\Middleware\JsonErrorMiddleware;
use App\Middleware\SessionMiddleware;
use Slim\Factory\AppFactory;

require __DIR__ . '/../config/bootstrap.php';

$app = AppFactory::create();

// Order matters: middleware added last runs first (LIFO).
// CsrfMiddleware is applied per route group in config/routes.php so that PSP
// webhook routes (signature-verified instead) can be excluded.
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$app->add(new SessionMiddleware());
$app->add(new JsonErrorMiddleware());

(require __DIR__ . '/../config/routes.php')($app);

$app->run();
