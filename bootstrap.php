<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Initialize database connection (hardcoded path, can be overridden by environment variable)
$dbPath = getenv('DB_PATH') ?: __DIR__ . '/db/app.sqlite';
$db = new App\Db(['path' => $dbPath]);

// Load all configuration from database
$settings = new App\Settings($db);
$config = $settings->getConfig();

// Set timezone from configuration
$appConfig = $config['app'] ?? [];
date_default_timezone_set($appConfig['timezone'] ?? 'UTC');

// Factory functions to lazily initialize service clients when needed
$servicenowFactory = function() use ($config, $appConfig) {
    return new App\ServiceNowClient($config['servicenow'], $appConfig);
};

$llmFactory = function() use ($config, $appConfig) {
    return new App\LlmClient($config['llm'], $appConfig);
};

return [
    'config' => $config,
    'db' => $db,
    'servicenowFactory' => $servicenowFactory,
    'llmFactory' => $llmFactory,
];