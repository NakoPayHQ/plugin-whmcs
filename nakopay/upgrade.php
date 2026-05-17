<?php
/**
 * Idempotent installer / upgrader. Run from WHMCS admin (browse to it once).
 * Creates the mod_nakopay_orders table if it does not exist.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/nakopay.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use NakoPay\Client;

$client = new Client();
$client->checkAdmin();
$client->createOrderTableIfNotExist();

echo 'NakoPay tables ready.';
