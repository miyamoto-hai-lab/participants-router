<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Router;

use App\Application\Actions\Router\AssignAction;
use App\Domain\Router\RouterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AssignActionTest extends TestCase
{
    public function testAssignAction()
    {
        $routerServiceProphecy = $this->prophesize(RouterService::class);
        $routerServiceProphecy
            ->assign('exp_1', 'browser_1', ['age' => 25])
            ->willReturn([
                'data' => ['status' => 'ok', 'url' => 'http://target.com', 'message' => null],
                'statusCode' => 200
            ])
            ->shouldBeCalled();

        $loggerProphecy = $this->prophesize(LoggerInterface::class);

        $action = new AssignAction($loggerProphecy->reveal(), $routerServiceProphecy->reveal());

        $request = $this->createRequest('POST', '/assign');
        $request = $request->withParsedBody([
            'experiment_id' => 'exp_1',
            'browser_id' => 'browser_1',
            'properties' => ['age' => 25]
        ]);
        $response = new \Slim\Psr7\Response();

        // Actions in Slim 4 are Invokable, but normally we test via app->handle
        // But to call directly we need to use __invoke($request, $response, $args)

        $response = $action($request, $response, []);

        $payload = (string) $response->getBody();
        // ActionPayload wraps data
        $expectedPayload = [
            'statusCode' => 200,
            'data' => ['status' => 'ok', 'url' => 'http://target.com', 'message' => null]
        ];
        $this->assertEquals($expectedPayload, json_decode($payload, true));
    }

    public function testAssignActionMissingParams()
    {
        $routerServiceProphecy = $this->prophesize(RouterService::class);
        $loggerProphecy = $this->prophesize(LoggerInterface::class);

        $action = new AssignAction($loggerProphecy->reveal(), $routerServiceProphecy->reveal());

        $request = $this->createRequest('POST', '/assign');
        $request = $request->withParsedBody([
            // missing params
        ]);
        $response = new \Slim\Psr7\Response();

        $response = $action($request, $response, []);

        $this->assertEquals(400, $response->getStatusCode());
    }
}
