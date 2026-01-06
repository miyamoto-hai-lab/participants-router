<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Domain\Participant\Participant;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);

            $loggerSettings = $settings->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },

        // Eloquent (DB) の初期化
        Capsule::class => function (ContainerInterface $c) {
            $settings = $c->get(SettingsInterface::class);
            $dbConfig = $settings->get('db');
            $tableName = $settings->get('db_table'); // 設定からテーブル名を取得

            $capsule = new Capsule;

            // 1. SQLiteのファイル自動作成
            if (($dbConfig['driver'] ?? '') === 'sqlite') {
                 $databasePath = $dbConfig['database'] ?? null;
                 if ($databasePath && !file_exists($databasePath)) {
                     try {
                         $dir = dirname($databasePath);
                         if (!is_dir($dir)) {
                             mkdir($dir, 0777, true);
                         }
                         file_put_contents($databasePath, '');
                         // ログ等はここでは出せないかも (Logger構築中 or 依存関係)
                     } catch (\Exception $e) {
                         // エラー処理: ログに出すか、dieするか
                         error_log("Failed to create sqlite database: " . $e->getMessage());
                     }
                 }
            }

            $capsule->addConnection($dbConfig);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            // 2. テーブル自動作成
            if ($tableName && !Capsule::schema()->hasTable($tableName)) {
                Capsule::schema()->create($tableName, function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->string('experiment_id')->index();
                    $table->string('browser_id');
                    $table->unique(['experiment_id', 'browser_id'], 'participants_unique_entry');
                    $table->string('condition_group');
                    $table->integer('current_step_index')->default(0);
                    $table->string('status')->default('assigned'); // assigned, completed
                    $table->timestamp('last_heartbeat')->useCurrent();
                    $table->json('metadata')->nullable();
                    $table->timestamps();
                });
            }

            // ここでModelにテーブル名を注入
            if ($tableName) {
                Participant::setGlobalTableName($tableName);
            }

            return $capsule;
        },
    ]);
};