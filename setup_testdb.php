<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

if (!function_exists('base_path')) {
    /**
     * Get the path to the application root directory.
     *
     * @param string|null $path
     * @return string
     */
    function base_path($path = null)
    {
        // プロジェクトのルートディレクトリを指すようにパスを調整してください。
        // __DIR__ は setup_db.php が存在するディレクトリを指します。
        return __DIR__ . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

// コンテナをロードして設定を取得（簡易的な方法）
$containerBuilder = new \DI\ContainerBuilder();
$settings = require __DIR__ . '/app/settings.php';
$settings($containerBuilder);
$dependencies = require __DIR__ . '/app/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

// 設定オブジェクトを取得
$config = $container->get(\App\Application\Settings\SettingsInterface::class);

// 'db.database' キーを使ってデータベースパスを取得
$databasePath = $config->get('db')['database'] ?? null;

// SQLite以外（MySQLなど）の場合は、ファイル作成ロジックをスキップ
if ($config->get('db')['driver'] === 'sqlite') {
    if (!file_exists($databasePath)) {
        try {
            $dir = dirname($databasePath);
            if (!is_dir($dir)) {
                // ディレクトリが存在しなければ作成
                mkdir($dir, 0777, true);
            }
            // 空のデータベースファイルを作成
            file_put_contents($databasePath, '');
            echo "Created missing database file: $databasePath\n";
        } catch (Exception $e) {
            die("Error creating database file: " . $e->getMessage() . "\n");
        }
    }
}

// Eloquentを起動
$container->get(Capsule::class);
$config = $container->get(\App\Application\Settings\SettingsInterface::class);
$tableName = $config->get('db_table');

if (!Capsule::schema()->hasTable($tableName)) {
    Capsule::schema()->create($tableName, function (Blueprint $table) {
        $table->id();
        $table->string('experiment_id')->index();
        $table->string('browser_id')->unique(); // browser_idはユニーク
        $table->string('worker_id')->nullable()->index();
        $table->string('condition_group');
        $table->integer('current_step_index')->default(0);
        $table->string('status')->default('assigned'); // assigned, completed
        $table->timestamp('last_heartbeat')->useCurrent();
        $table->text('metadata')->nullable();
        $table->timestamps();
    });
    echo "Table '$tableName' created successfully.\n";
} else {
    echo "Table '$tableName' already exists.\n";
}