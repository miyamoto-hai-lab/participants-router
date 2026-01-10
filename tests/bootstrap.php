<?php

require __DIR__ . '/../vendor/autoload.php';

// Create config_test.jsonc dynamically
$configFile = __DIR__ . '/../config.jsonc';
$testConfigFile = __DIR__ . '/../config_test.jsonc';

if (file_exists($configFile)) {
    $json = file_get_contents($configFile);
    // Do string replacement for replacing sqlite database.
    // Assuming "url": "..." structure for database.
    $customConfig = preg_replace(
        '/("url"\s*:\s*)"[^"]+"/',
        '$1"sqlite://:memory:"',
        $json
    );

    file_put_contents($testConfigFile, $customConfig);

    // Cleanup after tests
    register_shutdown_function(function () use ($testConfigFile) {
        if (file_exists($testConfigFile)) {
            unlink($testConfigFile);
        }
    });
}
