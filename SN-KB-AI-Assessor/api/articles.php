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
        error_log('[articles/bootstrap] ' . $e->getMessage());
    }
    exit;
}

/** @var Db $db */
$db = $container['db'];

header('Content-Type: application/json; charset=utf-8');

try {
    $params = $_GET;
    $query = isset($params['q']) ? trim((string)$params['q']) : '';
    $limitProvided = array_key_exists('limit', $params);
    $offsetProvided = array_key_exists('offset', $params);
    $limit = $limitProvided ? max(1, (int)$params['limit']) : null;
    $offset = $offsetProvided ? max(0, (int)$params['offset']) : null;

    $where = '';
    $bindings = [];

    if ($query !== '') {
        $where = 'WHERE kb_number LIKE :q OR short_description LIKE :q';
        $bindings['q'] = '%' . $query . '%';
    }

    $totalRow = $db->fetchOne(
        'SELECT COUNT(*) AS total FROM articles ' . $where,
        $bindings
    );
    $total = (int)($totalRow['total'] ?? 0);

    // Build SQL using heredoc to avoid fragile quote escaping
    $baseSql = <<<SQL
SELECT
    a.id,
    a.kb_number,
    a.short_description,
    a.sys_updated_on,
    (
        SELECT MAX(completed_at)
        FROM assessments s
        WHERE s.article_id = a.id AND s.status = 'done'
    ) AS last_assessed_at,
    (
        SELECT status
        FROM assessments s
        WHERE s.article_id = a.id
        ORDER BY s.id DESC
        LIMIT 1
    ) AS last_status,
    (
        SELECT verdict_current
        FROM assessments s
        WHERE s.article_id = a.id AND s.status = 'done'
        ORDER BY s.completed_at DESC
        LIMIT 1
    ) AS verdict_current
FROM articles a
$where
ORDER BY a.sys_updated_on DESC
SQL;

    if ($limit !== null) {
        $baseSql .= ' LIMIT :limit';
        if ($offset !== null) {
            $baseSql .= ' OFFSET :offset';
        }
    }

    $queryBindings = $bindings;
    if ($limit !== null) {
        $queryBindings['limit'] = $limit;
    }
    if ($offset !== null) {
        $queryBindings['offset'] = $offset;
    }

    $items = $db->fetchAll($baseSql, $queryBindings);

    $lastFetchRow = $db->fetchOne(
        'SELECT value FROM app_state WHERE key = :key',
        ['key' => 'last_fetch_at']
    );
    $lastFetchAt = $lastFetchRow['value'] ?? null;

    // Cast verdict_current from INT to bool/null
    foreach ($items as &$item) {
        if ($item['verdict_current'] === null) {
            continue;
        }
        $item['verdict_current'] = (int)$item['verdict_current'] === 1;
    }

    echo json_encode([
        'items' => $items,
        'total' => $total,
        'last_fetch_at' => $lastFetchAt,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);

    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[articles] ' . $e->getMessage());
    }
}