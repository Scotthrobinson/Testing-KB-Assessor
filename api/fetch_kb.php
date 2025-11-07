<?php

declare(strict_types=1);

use App\Db;

/** @var array{config: array, db: Db, servicenow: App\ServiceNowClient} $container */
try {
    $container = require __DIR__ . '/../bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Bootstrap failure: ' . $e->getMessage(),
    ]);
    if (function_exists('error_log')) {
        error_log('[fetch_kb/bootstrap] ' . $e->getMessage());
    }
    exit;
}

$db = $container['db'];
$serviceNow = $container['servicenowFactory']();

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $full = isset($input['full']) ? (bool)$input['full'] : false;
    $overrideSince = isset($input['since']) ? (string)$input['since'] : null;

    $lastFetchRow = $db->fetchOne('SELECT value FROM app_state WHERE key = :key', ['key' => 'last_fetch_at']);
    $lastFetchAt = $lastFetchRow['value'] ?? null;

    // If explicitly requested full, or override provided, honor that.
    // Otherwise, if no articles exist locally, do a full fetch (ignore last_fetch_at).
    $lastFetchRow = $db->fetchOne('SELECT value FROM app_state WHERE key = :key', ['key' => 'last_fetch_at']);
$lastFetchAt = $lastFetchRow['value'] ?? null;

// Determine the 'since' timestamp for filtering
if ($overrideSince !== null) {
    // Explicit override provided
    $since = $overrideSince;
} elseif ($full) {
    // Full fetch requested - fetch everything
    $since = null;
} else {
    // Incremental fetch - use last fetch time if articles exist, otherwise fetch all
    $countRow = $db->fetchOne('SELECT COUNT(*) AS c FROM articles');
    $hasArticles = (int)($countRow['c'] ?? 0) > 0;
    $since = $hasArticles ? $lastFetchAt : null;
}

    $articles = $serviceNow->fetchUpdatedArticles($since);

    $now = Db::now();
    $inserted = 0;
    $updated = 0;

    $db->transaction(static function (Db $conn) use ($articles, $now, &$inserted, &$updated): void {
        foreach ($articles as $record) {
            $kbNumber = (string)($record['number'] ?? '');
            if ($kbNumber === '') {
                continue;
            }

            $shortDescription = $record['short_description'] ?? null;
            $sysUpdatedOn = $record['sys_updated_on'] ?? $now;

            $existing = $conn->fetchOne(
                'SELECT id, sys_updated_on FROM articles WHERE kb_number = :kb_number',
                ['kb_number' => $kbNumber]
            );

            if ($existing === null) {
                $conn->insert(
                    'INSERT INTO articles (kb_number, short_description, sys_updated_on, body_html, created_at, updated_at)
                     VALUES (:kb_number, :short_description, :sys_updated_on, NULL, :created_at, :updated_at)',
                    [
                        'kb_number' => $kbNumber,
                        'short_description' => $shortDescription,
                        'sys_updated_on' => $sysUpdatedOn,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                $inserted++;
            } else {
                if ($existing['sys_updated_on'] !== $sysUpdatedOn) {
                    $conn->execute(
                        'UPDATE articles
                         SET short_description = :short_description,
                             sys_updated_on = :sys_updated_on,
                             updated_at = :updated_at,
                             body_html = NULL
                         WHERE id = :id',
                        [
                            'short_description' => $shortDescription,
                            'sys_updated_on' => $sysUpdatedOn,
                            'updated_at' => $now,
                            'id' => $existing['id'],
                        ]
                    );
                    $updated++;
                }
            }
        }
    });

    $db->execute(
        'INSERT INTO app_state (key, value) VALUES (:key, :value)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        ['key' => 'last_fetch_at', 'value' => $now]
    );

    echo json_encode([
        'fetched' => count($articles),
        'inserted' => $inserted,
        'updated' => $updated,
        'since_used' => $since,
        'last_fetch_at' => $now,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
    if (($container['config']['app']['log_errors'] ?? false) === true) {
        error_log('[fetch_kb] ' . $e->getMessage());
    }
}