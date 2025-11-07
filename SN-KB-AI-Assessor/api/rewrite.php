<?php

declare(strict_types=1);

use App\RewriteService;

// Increase execution time for rewrite operations (can take longer than assessments)
set_time_limit(180); // 3 minutes

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
        error_log('[rewrite/bootstrap] ' . $e->getMessage());
    }
    exit;
}

/** @var RewriteService $service */
$service = new RewriteService(
    $container['db'],
    $container['servicenowFactory'](),
    $container['llmFactory'](),
    $container['config']
);

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    
    $articleId = (int)($input['article_id'] ?? 0);
    $selectedRecommendations = $input['selected_recommendations'] ?? [];
    
    if ($articleId <= 0) {
        throw new InvalidArgumentException('article_id is required.');
    }
    
    if (!is_array($selectedRecommendations) || empty($selectedRecommendations)) {
        throw new InvalidArgumentException('No recommendations selected.');
    }
    
    $result = $service->rewriteArticle($articleId, $selectedRecommendations);
    
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'status' => 'error',
    ], JSON_THROW_ON_ERROR);
    
    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[rewrite] ' . $e->getMessage());
    }
}