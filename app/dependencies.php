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
            $capsule->addConnection($dbConfig);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            // ここでModelにテーブル名を注入
            if ($tableName) {
                Participant::setGlobalTableName($tableName);
            }

            return $capsule;
        },
    ]);
};