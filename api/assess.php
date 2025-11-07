<?php

declare(strict_types=1);

use App\AssessmentService;

try {
    $container = require __DIR__ . '/../bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Bootstrap failure: ' . $e->getMessage(),
        'status' => 'error',
    ]);
    if (function_exists('error_log')) {
        error_log('[assess/bootstrap] ' . $e->getMessage());
    }
    exit;
}

/** @var AssessmentService $service */
$service = new AssessmentService(
    $container['db'],
    $container['servicenowFactory'](),
    $container['llmFactory'](),
    $container['config']
);

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $articleId = isset($input['article_id']) ? (int)$input['article_id'] : 0;

    if ($articleId <= 0) {
        throw new InvalidArgumentException('article_id is required.');
    }

    $result = $service->queueAndProcess($articleId);

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'status' => 'error',
    ], JSON_THROW_ON_ERROR);

    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[assess] ' . $e->getMessage());
    }
}