<?php

declare(strict_types=1);

use App\Db;

try {
    $container = require __DIR__ . '/../bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Bootstrap failure: ' . $e->getMessage()]);
    exit;
}

/** @var Db $db */
$db = $container['db'];

header('Content-Type: application/json; charset=utf-8');

try {
    $articleId = isset($_GET['article_id']) ? (int)$_GET['article_id'] : 0;
    if ($articleId <= 0) {
        throw new InvalidArgumentException('article_id is required.');
    }

    $row = $db->fetchOne(
        'SELECT s.*, a.kb_number
         FROM assessments s
         JOIN articles a ON a.id = s.article_id
         WHERE s.article_id = :article_id
         ORDER BY s.completed_at DESC, s.id DESC
         LIMIT 1',
        ['article_id' => $articleId]
    );

    if (!$row) {
        throw new RuntimeException('No assessment found for article.');
    }

    echo json_encode([
        'id' => $row['id'],
        'article_id' => $row['article_id'],
        'kb_number' => $row['kb_number'],
        'status' => $row['status'],
        'verdict_current' => isset($row['verdict_current']) ? (int)$row['verdict_current'] === 1 : null,
        'recommendations' => $row['recommendations'] ? json_decode($row['recommendations'], true) : [],
        'raw_response' => $row['llm_raw'] ?? null,
        'requested_at' => $row['requested_at'],
        'started_at' => $row['started_at'],
        'completed_at' => $row['completed_at'],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[assessment_details] ' . $e->getMessage());
    }
}