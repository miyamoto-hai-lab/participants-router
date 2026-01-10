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
        // --- 診断用コード開始 ---
        $info = [
            'message' => 'Participants Router API is running.',
            
            // 1. PHP環境の基本設定（ここがUTCかJSTか確認）
            'php_basic' => [
                'version' => phpversion(),
                'default_timezone' => date_default_timezone_get(),
                'current_time_raw' => date('Y-m-d H:i:s'),
                'current_offset'   => date('P'), // +00:00 か +09:00 か
            ],
            
            // 2. Eloquent (Carbon) の設定
            'carbon_eloquent' => [
                'installed' => class_exists('\Carbon\Carbon'),
                'now'       => class_exists('\Carbon\Carbon') ? \Carbon\Carbon::now()->toDateTimeString() : 'N/A',
                'timezone'  => class_exists('\Carbon\Carbon') ? \Carbon\Carbon::now()->timezoneName : 'N/A',
            ],
        ];

        // 3. データベース接続テストと現在時刻の確認
        try {
            // Capsuleがグローバルで使える場合 (Eloquent利用なら通常使えるはずです)
            $pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();
            
            // DBサーバーの変数を直接取得
            $stmt = $pdo->query("SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone, NOW() as db_current_time");
            $dbData = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $info['database_check'] = [
                'status' => 'Connected',
                'pdo_driver' => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
                // DBが認識している設定
                'global_time_zone'  => $dbData['@@global.time_zone'],
                'session_time_zone' => $dbData['@@session.time_zone'], // ここが +00:00 なら成功
                'system_time_zone'  => $dbData['@@system_time_zone'],
                // DBが認識している「現在時刻」
                'db_current_time'   => $dbData['db_current_time'],
            ];
        } catch (\Exception $e) {
            $info['database_check'] = [
                'status' => 'Error',
                'message' => $e->getMessage(),
            ];
        }

        // 結果をJSONで見やすく整形して出力
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        // --- 診断用コード終了 ---
        return $response;
    });

    // API Routes
    $app->post('/assign', AssignAction::class);
    $app->post('/next', NextAction::class);
    $app->post('/heartbeat', HeartbeatAction::class);
};