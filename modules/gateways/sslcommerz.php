<?php

use WHMCS\Module\Gateway\Sslcommerz\SSLCommerzAPI;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function sslcommerz_MetaData()
{
    return [
        'DisplayName' => 'SSLCommerz',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function sslcommerz_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SSLCommerz',
        ],
        'store_id' => [
            'FriendlyName' => 'Store ID',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Collect Store ID from SSLCommerz',
        ],
        'store_password' => [
            'FriendlyName' => 'Store Password',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Collect Store Password from SSLCommerz',
        ],
        'fee' => [
            'FriendlyName' => 'Fee',
            'Type' => 'text',
            'Size' => '25',
            'Default' => 1.85,
            'Description' => 'Gateway fee if you want to add',
        ],
        'sandbox' => [
            'FriendlyName' => 'Sandbox',
            'Type' => 'yesno',
            'Description' => 'Tick to enable sandbox mode',
        ],
    ];
}

function sslcommerz_link($params)
{
    $url = $params['systemurl'] . '/modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $invId = $params['invoiceid'];
    $payTxt = $params['langpaynow'];
    $errorMsg = sslcommerz_handleErrors();

    return <<<HTML
    <form id="sslcommerz-form" method="GET" action="$url">
        <input type="hidden" name="action" value="init" />
        <input type="hidden" name="id" value="$invId" />
        <input class="btn btn-primary" type="submit" value="$payTxt" />
    </form>
    $errorMsg
    <script>
        var form = document.getElementById('sslcommerz-form');

        form.addEventListener("submit", function(e) {
            e.preventDefault();
            form.querySelector('input[type="submit"]').disabled = true;
            form.submit();
        });
    </script>
HTML;
}

function sslcommerz_refund($params)
{
    $sslcommerz = new SSLCommerzAPI(['store_id' => $params['store_id'], 'store_password' => $params['store_password'], 'sandbox' => !empty($params['sandbox'])]);

    try {
        $response = $sslcommerz->refund($params['transid'], $params['amount']);
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        ];
    }

    return [
        'status' => 'success',
        'rawdata' => $response->toArray(),
        'transid' => $response->refundRefId(),
        'fees' => 0,
    ];
}

function sslcommerz_handleErrors()
{
    $errors = [
        'lpa' => 'You paid less amount than required.',
        'tau' => 'The transaction already has been used.',
        'irs' => 'Invalid response from the bKash Server.',
        'ucnl' => 'You didn\'t completed the payment process.',
        'cancel' => 'You payment attempt was cancelled.',
        'failure' => 'Your payment attempt was failed.',
        'sww' => 'Something went wrong',
    ];

    $code = isset($_REQUEST['errorCode']) ? $_REQUEST['errorCode'] : null;
    if (empty($code)) {
        return null;
    }

    $error = isset($errors[$code]) ? $errors[$code] : $code;

    return '<div class="alert alert-danger" style="margin-top: 10px;" role="alert">' . $error . '</div>';
}
