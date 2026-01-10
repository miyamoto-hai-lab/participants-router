<?php

declare(strict_types=1);

namespace App\Application\Actions\Router;

use App\Application\Actions\Action;
use App\Domain\Router\RouterService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class NextAction extends Action
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
        $browserId = $data['browser_id'] ?? null;
        $currentUrl = $data['current_url'] ?? null;
        $properties = $data['properties'] ?? [];

        if (!$experimentId || !$browserId || !$currentUrl) {
            $missingParameters = array_keys(array_filter([
                'experiment_id' => $experimentId,
                'browser_id' => $browserId,
                'current_url' => $currentUrl,
            ], fn($value) => empty($value)));

            return $this->respondWithData(['status' => 'error', 'message' => 'Missing parameters: ' . implode(', ', $missingParameters)], 400);
        }

        $result = $this->routerService->next($experimentId, $browserId, $currentUrl, $properties);

        if (isset($result['data']) && isset($result['statusCode'])) {
            return $this->respondWithData($result['data'], $result['statusCode']);
        }

        return $this->respondWithData($result);
    }
}