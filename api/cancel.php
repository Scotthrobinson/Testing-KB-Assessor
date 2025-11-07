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
        error_log('[cancel/bootstrap] ' . $e->getMessage());
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

    // Find latest assessment ids for the provided article ids
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->fetchAll(
        "SELECT a.article_id, a.id AS assessment_id, a.status
         FROM assessments a
         WHERE a.id IN (
             SELECT MAX(id) FROM assessments
             WHERE article_id IN ($placeholders)
             GROUP BY article_id
         )",
        $ids
    );

    $updated = 0;
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    foreach ($rows as $row) {
        $assessmentId = (int)$row['assessment_id'];
        $status = $row['status'] ?? '';

        // Only update queued or running assessments
        if (in_array($status, ['queued', 'running'], true)) {
            $db->execute(
                'UPDATE assessments
                 SET status = :status, completed_at = :completed_at, error = :error
                 WHERE id = :id',
                [
                    'status' => 'error',
                    'completed_at' => $now,
                    'error' => 'Cancelled by user',
                    'id' => $assessmentId,
                ]
            );
            $updated++;
        }
    }

    echo json_encode([
        'message' => 'Cancellation attempted',
        'updated' => $updated,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);

    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[cancel] ' . $e->getMessage());
    }
}