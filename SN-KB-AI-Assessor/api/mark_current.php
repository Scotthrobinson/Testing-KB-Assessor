<?php

declare(strict_types=1);

use App\Db;

try {
    $container = require __DIR__ . '/../bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Bootstrap failure: ' . $e->getMessage()]);
    if (function_exists('error_log')) {
        error_log('[mark_current/bootstrap] ' . $e->getMessage());
    }
    exit;
}

/** @var Db $db */
$db = $container['db'];

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $articleId = isset($input['article_id']) ? (int)$input['article_id'] : 0;

    if ($articleId <= 0) {
        throw new InvalidArgumentException('article_id is required.');
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    // Insert a manual assessment marking it as current
    $db->transaction(function (Db $conn) use ($articleId, $now): void {
        // Ensure article exists
        $row = $conn->fetchOne('SELECT id FROM articles WHERE id = :id', ['id' => $articleId]);
        if ($row === null) {
            throw new RuntimeException('Article not found.');
        }

        // Create a "manual" assessment in done state
        $conn->insert(
            'INSERT INTO assessments (article_id, status, requested_at, started_at, completed_at, llm_model, verdict_current, recommendations)
             VALUES (:article_id, :status, :requested_at, :started_at, :completed_at, :llm_model, :verdict_current, :recommendations)',
            [
                'article_id' => $articleId,
                'status' => 'done',
                'requested_at' => $now,
                'started_at' => $now,
                'completed_at' => $now,
                'llm_model' => 'manual',
                'verdict_current' => 1,
                'recommendations' => json_encode([], JSON_THROW_ON_ERROR),
            ]
        );
    });

    echo json_encode([
        'ok' => true,
        'article_id' => $articleId,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);

    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[mark_current] ' . $e->getMessage());
    }
}