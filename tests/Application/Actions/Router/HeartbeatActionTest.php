<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Router;

use App\Application\Actions\Router\HeartbeatAction;
use App\Domain\Router\RouterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class HeartbeatActionTest extends TestCase
{
    public function testHeartbeatAction()
    {
        $routerServiceProphecy = $this->prophesize(RouterService::class);
        $routerServiceProphecy
            ->heartbeat('exp_1', 'browser_1')
            ->shouldBeCalled();

        $loggerProphecy = $this->prophesize(LoggerInterface::class);

        $action = new HeartbeatAction($loggerProphecy->reveal(), $routerServiceProphecy->reveal());

        $request = $this->createRequest('POST', '/heartbeat');
        $request = $request->withParsedBody([
            'experiment_id' => 'exp_1',
            'browser_id' => 'browser_1'
        ]);
        $response = new \Slim\Psr7\Response();

        $response = $action($request, $response, []);
        
        $payload = (string) $response->getBody();
        $expectedPayload = ['statusCode' => 200, 'data' => ['status' => 'ok']];
        $this->assertEquals($expectedPayload, json_decode($payload, true));
    }
}
