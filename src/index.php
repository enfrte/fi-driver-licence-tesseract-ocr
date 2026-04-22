<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\LicenceOCR;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

if (empty($_FILES['licence']) || $_FILES['licence']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

try {
    $ocr = new LicenceOCR();
    $result = $ocr->extract($_FILES['licence']['tmp_name']);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (\RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
