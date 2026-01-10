<?php

declare(strict_types=1);

namespace App\Application\Actions\Router;

use App\Application\Actions\Action;
use App\Domain\Router\RouterService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class HeartbeatAction extends Action
{
    private $routerService;

    public function __construct(LoggerInterface $logger, RouterService $routerService)
    {
        parent::__construct($logger);
        $this->routerService = $routerService;
    }

    protected function action(): Response
    {
        $data = $this->getFormData();
        $experimentId = $data['experiment_id'] ?? null;
        $participantId = $data['participant_id'] ?? null;

        if ($experimentId && $participantId) {
            $this->routerService->heartbeat($experimentId, $participantId);
        } else {
            $missingParameters = array_keys(array_filter([
                'experiment_id' => $experimentId,
                'participant_id' => $participantId,
            ], fn($value) => empty($value)));
            return $this->respondWithData([
                'status' => 'error',
                'message' => 'Missing parameters: ' . implode(', ', $missingParameters)
            ], 400);
        }

        return $this->respondWithData(['status' => 'ok']);
    }
}
