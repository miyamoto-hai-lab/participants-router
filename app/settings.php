<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {

    // JSONC (コメント付きJSON) を読み込むヘルパー関数
    $loadJsonc = function (string $path) {
        // error_log("Loading config from: $path");
        // error_log("File exists: " . (file_exists($path) ? 'yes' : 'no'));
        if (!file_exists($path)) {
            return [];
        }
        $json = file_get_contents($path);
        // コメント (//... や /*...*/) を除去
        // $json = preg_replace('!/\*.*?\*/!s', '', $json);
        // $json = preg_replace('/\n\s*\/\/.*$/m', '', $json);
        $json = preg_replace('~(" (?:\\\\. | [^"])*+ ") | // [^\\v]*+ | /\\* .*? \\*/~xsu', '$1', $json);
        // error_log("JSON content after removing comments: " . $json);
        return json_decode($json, true) ?? [];
    };

    // 設定読み込み
    $configPath = $_ENV['APP_CONFIG_PATH'] ?? __DIR__ . '/../config.jsonc';
    if (!str_starts_with($configPath, '/') && !str_starts_with($configPath, '\\') && !preg_match('/^[a-zA-Z]:/', $configPath)) {
        $configPath = __DIR__ . '/../' . $configPath;
    }
    
    $experimentConfig = $loadJsonc($configPath);
    
    // DB設定のデフォルト値 (SQLite)
    $dbConfig = $experimentConfig['database'] ?? [
        'driver' => 'sqlite',
        'database' => __DIR__ . '/../database.sqlite',
        'prefix' => '',
    ];
    
    // URL文字列からEloquent用の配列に変換 (config.jsoncの "url": "sqlite://..." 対応)
    if (isset($dbConfig['url'])) {
        $parts = parse_url($dbConfig['url']);
        if ($parts['scheme'] === 'sqlite') {
            $dbConfig = [
                'driver' => 'sqlite',
                'database' => (isset($parts['path']) && $parts['path'] !== ':memory:') 
                    ? __DIR__ . '/../' . ltrim($parts['path'], '/') 
                    : ($parts['path'] ?? ':memory:'),
                'prefix' => '',
            ];
            // :memory: の場合は特別扱い
            if ($parts['path'] === ':memory:') {
                $dbConfig['database'] = ':memory:';
            }
        } elseif ($parts['scheme'] === 'mysql') {
            $dbConfig = [
                'driver' => 'mysql',
                'host' => $parts['host'],
                'port' => $parts['port'] ?? 3306,
                'database' => ltrim($parts['path'], '/'),
                'username' => $parts['user'] ?? 'root',
                'password' => $parts['pass'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ];
        }
    }

    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () use ($experimentConfig, $dbConfig) {
            // echo "experimentConfig: ";
            // error_log(print_r($experimentConfig, true));
            // echo "dbConfig: ";
            // error_log(print_r($dbConfig, true));
            return new Settings([
                'displayErrorDetails' => true, // Productionではfalseに
                'logError'            => true,
                'logErrorDetails'     => true,
                'logger' => [
                    'name' => 'slim-app',
                    'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
                    'level' => Logger::DEBUG,
                ],
                // 実験設定とDB設定を注入
                'experiments' => $experimentConfig['experiments'] ?? [],
                'db' => $dbConfig,
                'db_table' => $experimentConfig['database']['table'] ?? 'participants',
                'base_path' => $experimentConfig['base_path'] ?? '',
            ]);
        }
    ]);
};