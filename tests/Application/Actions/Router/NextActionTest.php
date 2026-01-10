<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Router;

use App\Application\Actions\Router\NextAction;
use App\Domain\Router\RouterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class NextActionTest extends TestCase
{
    public function testNextAction()
    {
        $routerServiceProphecy = $this->prophesize(RouterService::class);
        $routerServiceProphecy
            ->next('exp_1', 'participant_1', 'http://current.com', ['prop' => 1])
            ->willReturn([
                'data' => ['status' => 'ok', 'url' => 'http://next.com', 'message' => null],
                'statusCode' => 200
            ])
            ->shouldBeCalled();

        $loggerProphecy = $this->prophesize(LoggerInterface::class);

        $action = new NextAction($loggerProphecy->reveal(), $routerServiceProphecy->reveal());

        $request = $this->createRequest('POST', '/next');
        $request = $request->withParsedBody([
            'experiment_id' => 'exp_1',
            'participant_id' => 'participant_1',
            'current_url' => 'http://current.com',
            'properties' => ['prop' => 1]
        ]);
        $response = new \Slim\Psr7\Response();

        $response = $action($request, $response, []);

        $payload = (string) $response->getBody();
        $expectedPayload = [
            'statusCode' => 200,
            'data' => ['status' => 'ok', 'url' => 'http://next.com', 'message' => null]
        ];
        $this->assertEquals($expectedPayload, json_decode($payload, true));
    }
}
