<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$deps = require __DIR__ . '/../bootstrap.php';
/** @var App\Db $db */
$db = $deps['db'];

try {
    $input = file_get_contents('php://input');
    if ($input === false) {
        throw new RuntimeException('Failed to read request body.');
    }

    $data = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data) || empty($data)) {
        throw new RuntimeException('Invalid or empty settings data.');
    }

    $settings = new App\Settings($db);
    $settings->updateMultiple($data);

    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully',
    ], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
