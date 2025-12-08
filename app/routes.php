<?php

declare(strict_types=1);

use App\Application\Actions\Router\AssignAction;
use App\Application\Actions\Router\NextAction;
use App\Application\Actions\Router\HeartbeatAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    // CORS対応 (ResponseEmitterでヘッダーは付与されるが、OPTIONSメソッドを受け付ける必要がある)
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Participants Router API is running.');
        return $response;
    });

    // API Routes
    $app->post('/assign', AssignAction::class);
    $app->post('/next', NextAction::class);
    $app->post('/heartbeat', HeartbeatAction::class);
};