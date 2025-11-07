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
        error_log('[delete/bootstrap] ' . $e->getMessage());
    }
    exit;
}

/** @var Db $db */
$db = $container['db'];

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $ids = [];
    if (isset($input['article_ids']) && is_array($input['article_ids'])) {
        foreach ($input['article_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    if ($ids === []) {
        http_response_code(400);
        echo json_encode(['error' => 'article_ids must be a non-empty array of positive integers'], JSON_THROW_ON_ERROR);
        return;
    }

    // Build placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $db->fetchAll(
            "SELECT ... WHERE article_id IN ($placeholders)",
            $ids
        );
    // Perform delete in a transaction; ON DELETE CASCADE will remove assessments
    $deleted = 0;
    $db->transaction(function (Db $conn) use ($ids, $placeholders, &$deleted): void {
        // Optional: count before delete if needed
        $existing = $conn->fetchAll('SELECT id FROM articles WHERE id IN (' . $placeholders . ')', $ids);
        if ($existing === null) {
            $deleted = 0;
            return;
        }

        $conn->execute('DELETE FROM articles WHERE id IN (' . $placeholders . ')', $ids);
        $deleted = is_array($existing) ? count($existing) : 0;
    });

    echo json_encode([
        'deleted' => $deleted,
        'message' => "Deleted {$deleted} article(s) and associated assessments",
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);

    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[delete] ' . $e->getMessage());
    }
}