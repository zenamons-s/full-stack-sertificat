<?php

declare(strict_types=1);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_URI'] ?? '/') === '/health') {
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
    return;
}

http_response_code(404);
echo json_encode(['status' => 'not_found'], JSON_THROW_ON_ERROR);
