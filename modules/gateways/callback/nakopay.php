<?php
/**
 * NakoPay → WHMCS webhook receiver.
 *
 * Verifies the X-NakoPay-Signature HMAC, then dispatches on event type.
 * On invoice.paid (with the configured number of network confirmations met
 * server-side) we call addInvoicePayment() to mark the WHMCS invoice paid.
 *
 *   Header:  X-NakoPay-Signature: t=<unix>,v1=<hmac_sha256_hex>
 *   Signed string: "<t>.<raw_request_body>"
 *   Algorithm:    HMAC-SHA256 with the endpoint signing secret
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../nakopay/nakopay.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use NakoPay\Client;

$client    = new Client();
$gateway   = 'nakopay';
$params    = getGatewayVariables($gateway);

if (empty($params['type'])) {
    http_response_code(503);
    exit('Module not activated');
}

$rawBody = file_get_contents('php://input') ?: '';
$sig     = $_SERVER['HTTP_X_NAKOPAY_SIGNATURE'] ?? '';

if (!$client->verifyWebhook($rawBody, $sig, $params['WebhookSecret'] ?? null)) {
    http_response_code(401);
    logTransaction($params['name'], ['headers_sig' => $sig, 'body' => $rawBody], 'Signature failed');
    exit(json_encode(['error' => 'invalid signature']));
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit(json_encode(['error' => 'invalid json']));
}

$type    = (string) ($payload['type'] ?? $payload['event'] ?? '');
$data    = $payload['data']['object'] ?? $payload['data'] ?? $payload['invoice'] ?? $payload;
$invId   = (string) ($data['id'] ?? '');
$status  = (string) ($data['status'] ?? '');
$txHash  = (string) ($data['tx_hash'] ?? '');
$amount  = (float)  ($data['amount']  ?? 0);
$meta    = $data['metadata'] ?? [];
$whmcsId = (int)    ($meta['whmcs_invoice_id'] ?? 0);

// Fall back to our own table if the metadata didn't ship the WHMCS id.
if (!$whmcsId && $invId) {
    $row = $client->getOrderByNakoId($invId);
    if ($row) {
        $whmcsId = (int) $row['whmcs_invoice_id'];
        if (!$amount) $amount = (float) $row['amount_fiat'];
    }
}

// Always reflect the latest status in our local table.
if ($invId && $status) {
    $client->updateStatus($invId, $status, $txHash ?: null);
}

switch ($type) {
    case 'invoice.paid':
    case 'invoice.completed':
        if (!$whmcsId) {
            http_response_code(202);
            exit(json_encode(['received' => true, 'note' => 'no whmcs invoice id in metadata']));
        }

        try {
            $whmcsId = checkCbInvoiceID($whmcsId, $params['name']);
        } catch (\Throwable $e) {
            http_response_code(202);
            exit(json_encode(['received' => true, 'note' => 'invoice not found in whmcs']));
        }

        $txnId = $txHash ?: $invId;
        if (checkCbTransID($txnId)) {
            // Already recorded - idempotent.
            exit(json_encode(['received' => true, 'note' => 'duplicate']));
        }

        addInvoicePayment($whmcsId, $txnId, $amount, 0, $gateway);
        logTransaction($params['name'], $payload, 'Successful');
        echo json_encode(['received' => true]);
        return;

    case 'invoice.expired':
    case 'invoice.cancelled':
        logTransaction($params['name'], $payload, 'Unsuccessful');
        echo json_encode(['received' => true]);
        return;

    default:
        // Unknown / informational events - log and acknowledge.
        logTransaction($params['name'], $payload, 'Information');
        echo json_encode(['received' => true]);
        return;
}
