<?php

// Router for `php -S 127.0.0.1:<port>`, used only by tests/run.php to exercise
// CurlTransport over a real socket on localhost. Never reachable from outside.

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/redirect') {
    header('Location: http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/followed', true, 302);
    header('Content-Type: application/json');
    echo '{"redirected":true}';

    return true;
}

if ($path === '/slow') {
    usleep(1_500_000);
    header('Content-Type: application/json');
    echo '{"slow":true}';

    return true;
}

if (str_ends_with($path, '/error')) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode([
        'code' => 'VALIDATION_FAILED',
        'message' => 'The amount is below the minimum.',
        'traceId' => 'tr_local',
        'fields' => [['path' => 'amountCents', 'message' => 'At least 1000']],
    ]);

    return true;
}

$headers = array_change_key_case(getallheaders(), CASE_LOWER);

header('Content-Type: application/json');
header('X-Custom-Header: Hello');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'authorization' => $headers['authorization'] ?? null,
    'idempotencyKey' => $headers['idempotency-key'] ?? null,
    'contentType' => $headers['content-type'] ?? null,
    'userAgent' => $headers['user-agent'] ?? null,
    'body' => file_get_contents('php://input'),
]);

return true;
