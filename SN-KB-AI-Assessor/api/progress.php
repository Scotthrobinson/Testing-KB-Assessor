<?php

declare(strict_types=1);

use App\Db;

try {
    $container = require __DIR__ . '/../bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Bootstrap failure: ' . $e->getMessage(),
    ]);
    if (function_exists('error_log')) {
        error_log('[progress/bootstrap] ' . $e->getMessage());
    }
    exit;
}

/** @var Db $db */
$db = $container['db'];

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $ids = isset($input['article_ids']) && is_array($input['article_ids'])
        ? array_values(array_filter(array_map('intval', $input['article_ids'])))
        : [];

    if ($ids === []) {
        throw new InvalidArgumentException('article_ids must be a non-empty array.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT article_id, status
         FROM assessments
         WHERE id IN (
             SELECT MAX(id)
             FROM assessments
             WHERE article_id IN ($placeholders)
             GROUP BY article_id
         )",
        $ids
    );

    $stats = [
        'queued' => 0,
        'running' => 0,
        'done' => 0,
        'error' => 0,
    ];

    foreach ($rows as $row) {
        $status = $row['status'] ?? '';
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }

    echo json_encode([
        'total' => array_sum($stats),
        'stats' => $stats,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);

    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[progress] ' . $e->getMessage());
    }
}