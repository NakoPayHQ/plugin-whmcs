<?php
/**
 * NakoPay WHMCS payment gateway.
 *
 * Drop the contents of plugins/whmcs/ into <whmcs>/modules/gateways/ so the
 * resulting layout is:
 *
 *   modules/gateways/nakopay.php                       (this file)
 *   modules/gateways/nakopay/...                       (helper class, templates, assets)
 *   modules/gateways/callback/nakopay.php              (webhook receiver)
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/nakopay/nakopay.php';

use NakoPay\Client;

function nakopay_MetaData()
{
    return [
        'DisplayName'                 => 'NakoPay (Bitcoin)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function nakopay_config()
{
    $client = new Client();
    $client->createOrderTableIfNotExist();
    require_once $client->getLangFilePath();

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'NakoPay (Bitcoin)',
        ],
        [
            'FriendlyName' => '<span style="color:grey;">Version</span>',
            'Description'  => '<span style="color:grey;">' . $client->getVersion() . '</span>',
        ],
        'ApiKey' => [
            'FriendlyName' => 'API Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Your secret key. Use sk_test_... while testing, sk_live_... in production. <a href="https://nakopay.com/dashboard/api-keys" target="_blank">Get an API key</a>.',
        ],
        'WebhookSecret' => [
            'FriendlyName' => 'Webhook Signing Secret',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Paste the signing secret shown when you created the webhook endpoint in NakoPay.',
        ],
        'WebhookUrl' => [
            'FriendlyName' => 'Your Webhook URL',
            'Type'         => 'text',
            'Size'         => '80',
            'Description'  => 'Copy this URL into the NakoPay dashboard when adding a webhook endpoint.',
            'Default'      => $client->getWebhookUrl(),
        ],
        'TestMode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Cosmetic - the API key prefix already determines live vs test mode.',
        ],
        'Confirmations' => [
            'FriendlyName' => 'Confirmations',
            'Type'         => 'dropdown',
            'Default'      => '1',
            'Options'      => [
                '0' => '0 (instant - zero-conf)',
                '1' => '1 (recommended)',
                '2' => '2 (extra safe, slower)',
            ],
            'Description'  => 'Network confirmations required before the WHMCS invoice is marked paid.',
        ],
    ];
}

/**
 * Render the "Pay Now" button on the WHMCS invoice page.
 *
 * Strategy: defer invoice creation to the moment the customer actually clicks
 * Pay Now. The button posts the WHMCS invoice id + amount to payment.php,
 * which then calls the NakoPay API and hands the customer a checkout view.
 */
function nakopay_link($params)
{
    if (!is_array($params) || empty($params)) {
        exit('[NakoPay] Missing $params data.');
    }

    $form_url  = \App::getSystemURL() . 'modules/gateways/nakopay/payment.php';
    $btnLabel  = isset($params['langpaynow']) ? $params['langpaynow'] : 'Pay with Bitcoin';

    $fields = [
        'whmcs_invoice_id' => $params['invoiceid'],
        'amount'           => $params['amount'],
        'currency'         => $params['currency'],
        'description'      => 'Invoice #' . $params['invoiceid'] . ' - ' . $params['companyname'],
        'customer_email'   => $params['clientdetails']['email'] ?? '',
    ];

    $form  = '<form action="' . htmlspecialchars($form_url) . '" method="POST">';
    foreach ($fields as $name => $value) {
        $form .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) $value) . '"/>';
    }
    $form .= '<button type="submit" class="btn btn-primary">' . htmlspecialchars($btnLabel) . ' &raquo;</button>';
    $form .= '</form>';

    return $form;
}
