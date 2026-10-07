<?php

declare(strict_types=1);

// A server that speaks what the captcha services speak: it gets a POST with
// `secret` and `response`, and it answers by the response (a token of the
// tests). It writes what it received, one JSON per line, in VERIFY_LOG.

$log = getenv('VERIFY_LOG');
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
    'fields' => $_POST,
]) . "\n", FILE_APPEND);

$answers = [
    'ok' => ['success' => true, 'score' => 0.9, 'action' => 'contact', 'hostname' => 'localhost'],
    'low' => ['success' => true, 'score' => 0.2, 'action' => 'contact'],
    'edge' => ['success' => true, 'score' => 0.5, 'action' => 'contact'],
    'other-action' => ['success' => true, 'score' => 0.9, 'action' => 'login'],
    'no-score' => ['success' => true, 'action' => 'contact'],
    'no-action' => ['success' => true, 'score' => 0.9],
    'plain-ok' => ['success' => true],
    'turnstile-ok' => ['success' => true, 'action' => 'contact'],
    'turnstile-other' => ['success' => true, 'action' => 'login'],
    'failed' => ['success' => false, 'error-codes' => ['invalid-input-response']],
];

$response = $_POST['response'] ?? '';

if ($response === 'server-error') {
    http_response_code(500);
    echo 'boom';

    return;
}

if ($response === 'garbage') {
    echo 'this is not a JSON';

    return;
}

header('Content-Type: application/json');
echo json_encode($answers[$response] ?? ['success' => false]);
