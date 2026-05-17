<?php
/**
 * Admin-only API key smoke test.
 *
 * Hits invoices-get with an obviously-fake id. We don't care about the body -
 * a 4xx with a valid JSON error envelope means the key authenticated. A 401 /
 * network error means the key is wrong or HTTPS is blocked.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/nakopay.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use NakoPay\Client;

header('Content-Type: application/json');

$client = new Client();
$client->checkAdmin();

try {
    $resp = $client->getInvoice('in_test_does_not_exist');
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    exit;
}

if (($resp['_status'] ?? 0) === 0) {
    echo json_encode(['ok' => false, 'message' => 'Network error - the server could not reach NakoPay. Check outbound HTTPS.']);
    exit;
}

if (($resp['_status'] ?? 0) === 401 || ($resp['_status'] ?? 0) === 403) {
    echo json_encode(['ok' => false, 'message' => 'API key was rejected. Double-check the key in NakoPay → Dashboard → API keys.']);
    exit;
}

// 404 / 400 with a JSON envelope = key authenticated fine, the test id just doesn't exist.
echo json_encode(['ok' => true, 'message' => 'NakoPay API reachable and key authenticated.']);
