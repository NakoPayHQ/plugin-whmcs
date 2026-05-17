<?php
/**
 * Customer-facing checkout controller.
 *
 *  POST /modules/gateways/nakopay/payment.php
 *      whmcs_invoice_id, amount, currency, description, customer_email
 *      → creates a NakoPay invoice (or reuses an open one) and renders checkout.
 *
 *  GET  ?invoice=in_xxx                → render checkout page for an existing order
 *  GET  ?poll=in_xxx                   → JSON status (called every 5s by checkout.js)
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/nakopay.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use NakoPay\Client;
use WHMCS\ClientArea;

define('CLIENTAREA', true);

$client = new Client();
require $client->getLangFilePath(isset($_GET['language']) ? (string) $_GET['language'] : 'english');

/* --------------------------------------------------------------- JSON poll */

if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_GET['poll']);
    $order = $client->getOrderByNakoId($id);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'unknown invoice']);
        exit;
    }

    $api = $client->getInvoice($id);
    if (!empty($api['_ok'])) {
        $client->updateStatus($id, (string) ($api['status'] ?? $order['status']), $api['tx_hash'] ?? null);
        $order = $client->getOrderByNakoId($id) ?: $order;
    }

    echo json_encode([
        'status'        => $order['status'],
        'address'       => $order['address'],
        'amount_crypto' => $order['amount_crypto'],
        'coin'          => $order['coin'],
        'currency'      => $order['currency'],
        'amount_fiat'   => $order['amount_fiat'],
        'tx_hash'       => $order['tx_hash'],
        'redirect'      => in_array($order['status'], ['paid', 'completed'], true)
            ? \App::getSystemURL() . 'viewinvoice.php?id=' . (int) $order['whmcs_invoice_id'] . '&paymentsuccess=true'
            : null,
    ]);
    exit;
}

/* ------------------------------------------------------------- POST → create */

$order = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['whmcs_invoice_id'])) {
    $whmcsInvoiceId = (int) $_POST['whmcs_invoice_id'];

    // Reuse an in-flight order for this WHMCS invoice if there is one.
    $order = $client->getOpenOrderForInvoice($whmcsInvoiceId);
    if (!$order) {
        $resp = $client->createInvoice([
            'whmcs_invoice_id' => $whmcsInvoiceId,
            'amount'           => $_POST['amount'] ?? '0',
            'currency'         => $_POST['currency'] ?? 'USD',
            'coin'             => 'BTC',
            'description'      => $_POST['description'] ?? ('Invoice #' . $whmcsInvoiceId),
            'customer_email'   => $_POST['customer_email'] ?? '',
        ]);
        if (empty($resp['_ok']) || empty($resp['id'])) {
            http_response_code(502);
            echo '<h2>Could not start a Bitcoin payment.</h2>';
            echo '<p>The NakoPay API returned: <code>' . htmlspecialchars((string) ($resp['error']['message'] ?? $resp['_error'] ?? 'unknown error')) . '</code></p>';
            echo '<p>Ask the merchant to check the API key in WHMCS &rarr; Setup &rarr; Payment Gateways &rarr; NakoPay.</p>';
            exit;
        }
        $client->saveOrder([
            'whmcs_invoice_id'   => $whmcsInvoiceId,
            'nakopay_invoice_id' => $resp['id'],
            'address'            => $resp['address']     ?? null,
            'coin'               => $resp['coin']        ?? 'BTC',
            'currency'           => $resp['currency']    ?? 'USD',
            'amount_fiat'        => $resp['amount']      ?? 0,
            'amount_crypto'      => $resp['amount_crypto'] ?? 0,
            'status'             => $resp['status']      ?? 'pending',
            'checkout_url'       => $resp['checkout_url'] ?? null,
            'bip21'              => $resp['bip21']       ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
        $order = $client->getOrderByNakoId($resp['id']);
    }
}

/* ------------------------------------------------------- GET ?invoice=in_xxx */

if (!$order && isset($_GET['invoice'])) {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_GET['invoice']);
    $order = $client->getOrderByNakoId($id);
}

if (!$order) {
    http_response_code(400);
    echo '<h2>No active Bitcoin payment found.</h2>';
    echo '<p><a href="' . \App::getSystemURL() . 'clientarea.php?action=invoices">Return to invoices</a></p>';
    exit;
}

/* ---------------------------------------------------- render Smarty checkout */

$ca = new ClientArea();
$ca->setPageTitle('Bitcoin Payment');
$ca->addToBreadCrumb('index.php', 'Home');
$ca->addToBreadCrumb('clientarea.php?action=invoices', 'Invoices');
$ca->addToBreadCrumb('#', 'Bitcoin Payment');
$ca->initPage();

$ca->setTemplate(__DIR__ . '/assets/templates/checkout.tpl');
$ca->assign('order', $order);
$ca->assign('asset_base', \App::getSystemURL() . 'modules/gateways/nakopay/assets');
$ca->assign('poll_url', \App::getSystemURL() . 'modules/gateways/nakopay/payment.php?poll=' . urlencode($order['nakopay_invoice_id']));
$ca->assign('return_url', \App::getSystemURL() . 'viewinvoice.php?id=' . (int) $order['whmcs_invoice_id']);
$ca->output();
