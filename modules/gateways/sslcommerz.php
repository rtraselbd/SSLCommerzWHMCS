<?php

use WHMCS\Module\Gateway\Sslcommerz\SSLCommerzAPI;
use WHMCS\Module\Gateway\Sslcommerz\Storage;

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
        'transid_source' => [
            'FriendlyName' => 'Recorded Transaction ID',
            'Type' => 'dropdown',
            'Options' => 'tran_id,bank_tran_id',
            'Default' => 'tran_id',
            'Description' => 'Which SSLCommerz ID is stored as the WHMCS transaction ID. Refunds work either way, both IDs are kept in the mod_sslcommerz_transactions table.',
        ],
        'sandbox' => [
            'FriendlyName' => 'Sandbox',
            'Type' => 'yesno',
            'Description' => 'Tick to enable sandbox mode',
        ],
        'force_new_ui' => [
            'FriendlyName' => 'Force New Checkout UI',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Tick to force the redesigned EasyCheckout UI. Leave unticked to use legacy checkout UI.',
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

    $record = Storage::find((string) $params['transid']);

    try {
        // The refund API only accepts the bank transaction ID, whatever WHMCS holds.
        $bankTrxId = sslcommerz_bankTrxId($sslcommerz, $record, (string) $params['transid']);

        $response = $sslcommerz->refund($bankTrxId, $params['amount']);
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'rawdata' => $e->getMessage(),
        ];
    }

    if (!$response->success() && !$response->processing()) {
        return [
            'status' => 'declined',
            'rawdata' => $response->toArray(),
        ];
    }

    // Resolving the bank ID may have created the row this started without.
    $record = $record ?: Storage::find((string) $params['transid']);

    if ($record) {
        Storage::save($record->tran_id, [
            'status' => 'refund_' . $response->status(),
            'refund_ref_id' => $response->refundRefId(),
        ]);
    }

    return [
        'status' => 'success',
        'rawdata' => $response->toArray(),
        'transid' => $response->refundRefId(),
        'fees' => 0,
    ];
}

/**
 * Resolve the bank transaction ID a refund needs from whichever ID WHMCS has
 * recorded against the payment.
 */
function sslcommerz_bankTrxId(SSLCommerzAPI $sslcommerz, $record, $transId)
{
    if (!empty($record->bank_tran_id)) {
        return $record->bank_tran_id;
    }

    $tranId = !empty($record->tran_id) ? $record->tran_id : $transId;

    try {
        $bankTrxId = $sslcommerz->bankTrxId($tranId);
    } catch (\Exception $e) {
        $bankTrxId = null;
    }

    // Payments taken before the ledger existed already store the bank ID.
    if (empty($bankTrxId)) {
        return $transId;
    }

    Storage::save($tranId, ['bank_tran_id' => $bankTrxId]);

    return $bankTrxId;
}

function sslcommerz_handleErrors()
{
    $errors = [
        'lpa' => 'You paid less amount than required.',
        'tau' => 'The transaction already has been used.',
        'imm' => 'The transaction belongs to another invoice.',
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
