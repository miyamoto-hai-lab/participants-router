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
        $browserId = $data['browser_id'] ?? null; // CookieではなくBodyから取得
        $properties = $data['properties'] ?? [];

        if (!$experimentId || !$browserId) {
            return $this->respondWithData(['status' => 'error', 'message' => 'Missing parameters'], 400);
        }

        $result = $this->routerService->next($experimentId, $browserId, $properties);

        return $this->respondWithData($result);
    }
}