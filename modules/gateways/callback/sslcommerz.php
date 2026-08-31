<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Module\Gateway\Sslcommerz\SSLCommerzAPI;
use WHMCS\Module\Gateway\Sslcommerz\Storage;

class SSLCommerzCheckout
{
    private static $instance;

    protected $gatewayModuleName;

    public $gatewayParams;

    public $isActive;

    protected $customerCurrency;

    protected $gatewayCurrency;

    protected $clientCurrency;

    protected $convoRate;

    protected $invoice;

    protected $client;

    protected $due;

    protected $fee;

    public $total;

    public $request;

    public $sslcommerz;

    private $credential;

    private function __construct()
    {
        $this->setRequest();
        $this->setGateway();
        $this->setInvoice();
        $this->setClient();
    }

    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new SSLCommerzCheckout;
        }

        return self::$instance;
    }

    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isActive          = ! empty($this->gatewayParams['type']);

        $this->credential = [
            'store_id'       => $this->gatewayParams['store_id'],
            'store_password' => $this->gatewayParams['store_password'],
            'sandbox'        => ! empty($this->gatewayParams['sandbox']),
        ];

        $this->sslcommerz = new SSLCommerzAPI($this->credential);
    }

    private function setRequest()
    {
        $this->request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    }

    private function setInvoice()
    {
        // An IPN URL configured in the merchant panel carries no query string,
        // so fall back to the invoice id echoed back in value_a.
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id') ?: $this->request->get('value_a'),
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    private function setClient()
    {
        $this->client = localAPI('GetClientsDetails', [
            'clientid' => $this->invoice['userid'],
        ]);
    }

    private function setCurrency()
    {
        // Gateway currency (BDT)
        $this->gatewayCurrency = (int) $this->gatewayParams['convertto'];

        // Customer currency (USD)
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (! empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            // Get base currency rate (BDT rate)
            $baseCurrencyRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');

            // Get customer currency rate (USD rate)
            $customerCurrencyRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->customerCurrency)
                ->value('rate');

            // Calculate conversion rate (BDT to USD)
            $this->convoRate = $baseCurrencyRate / $customerCurrencyRate;
        } else {
            $this->convoRate = 1;
        }
    }

    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    private function checkTransaction($trxId)
    {
        return localAPI('GetTransactions', ['transid' => $trxId]);
    }

    private function belongsToInvoice($transactions)
    {
        $found = isset($transactions['transactions']['transaction'])
            ? $transactions['transactions']['transaction']
            : [];

        foreach ($found as $transaction) {
            if ((int) $transaction['invoiceid'] === (int) $this->invoice['invoiceid']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record an attempt that is being turned away. Without this the gateway log
     * holds nothing at all for a notification that never became a payment.
     */
    private function logRejection($reason, $payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'reason'                 => $reason,
                'invoice_id'             => $this->request->get('id') ?: $this->request->get('value_a'),
            ],
            $reason
        );
    }

    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'request_data'           => $this->request->request->all(),
            ],
            $payload['status']
        );
    }

    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => $this->fee,
        ];
        $add = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    private function generateTrxId()
    {
        return 'INV' . $this->invoice['invoiceid'] . '-' . uniqid();
    }

    /**
     * The identifier WHMCS stores in its transaction id column. Both ids stay
     * available through the local ledger, so either one can be recorded here.
     */
    private function resolveTrxId($response)
    {
        if (($this->gatewayParams['transid_source'] ?? 'tran_id') === 'bank_tran_id') {
            return $response->bankTranId() ?: $response->tranId();
        }

        return $response->tranId() ?: $response->bankTranId();
    }

    private function recordTransaction($response)
    {
        Storage::save($response->tranId(), [
            'invoice_id'   => (int) ($response->valueA() ?: $this->invoice['invoiceid']),
            'val_id'       => $response->valId(),
            'bank_tran_id' => $response->bankTranId(),
            'card_type'    => $response->cardType(),
            'amount'       => $response->amount(),
            'currency'     => $response->currency(),
            'status'       => $response->status(),
        ]);
    }

    public function createPayment()
    {
        $systemUrl   = \WHMCS\Config\Setting::getValue('SystemURL');
        $callbackURL = $systemUrl . '/modules/gateways/callback/' . $this->gatewayModuleName . '.php?id=' . $this->invoice['invoiceid'];
        $trxId       = $this->generateTrxId();

        $fields = [
            'tran_id'      => $trxId,
            'amount'       => $this->total,
            'invoice_id'   => $this->invoice['invoiceid'],
            'callback_url' => $callbackURL,
            'name'         => $this->client['fullname'],
            'email'        => $this->client['email'],
            'phone'        => '0' . $this->client['phonenumber'],
            'address'      => $this->client['address1'],
            'city'         => $this->client['city'],
            'country'      => $this->client['countryname'],
        ];

        // Kept even if the customer never returns, so the attempt stays traceable.
        Storage::begin([
            'invoice_id'     => $this->invoice['invoiceid'],
            'tran_id'        => $trxId,
            'amount'         => $this->total,
            'currency'       => 'BDT',
            // The invoice-currency figure behind that BDT total, so the rate this
            // payment was taken at stays known long after the rates move on.
            'invoice_amount' => $this->due,
        ]);

        return $this->sslcommerz->checkout($fields);
    }

    /**
     * Server to server notification from SSLCommerz. It arrives whether or not
     * the customer ever returns to the site, so it is the path that settles a
     * payment when the browser never makes it back.
     */
    public function handleIpn()
    {
        $payload = $this->request->request->all();
        $status  = strtoupper((string) $this->request->get('status'));

        // The validation API is the real authority, this only turns away noise.
        if ($this->sslcommerz->signatureMatches($payload) === false) {
            $this->logRejection('IPN signature verification failed', $payload);

            return [
                'status'    => 'error',
                'message'   => 'Signature verification failed.',
                'errorCode' => 'sig',
            ];
        }

        // Nothing downstream can attribute the money without an invoice, and a
        // retry will not change that, so it is recorded and closed off here.
        if (empty($this->invoice['invoiceid'])) {
            $this->logRejection('IPN could not be matched to an invoice', $payload);

            return [
                'status'    => 'error',
                'message'   => 'No invoice matches this notification.',
                'errorCode' => 'inv',
            ];
        }

        if ($status !== 'VALID' && $status !== 'VALIDATED') {
            Storage::save($this->request->get('tran_id'), ['status' => strtolower($status)]);

            return [
                'status'  => 'ignored',
                'message' => 'Nothing to record for status: ' . $status,
            ];
        }

        return $this->makeTransaction();
    }

    public function makeTransaction()
    {
        try {
            $response = $this->sslcommerz->verify($this->request->get('val_id'));

            if ($response->success()) {
                $this->recordTransaction($response);

                $invoiceId = isset($this->invoice['invoiceid']) ? $this->invoice['invoiceid'] : null;

                if (! empty($response->valueA()) && (int) $response->valueA() !== (int) $invoiceId) {
                    $this->logRejection('Transaction belongs to invoice ' . $response->valueA(), $response->toArray());

                    return [
                        'status'    => 'error',
                        'message'   => 'The transaction belongs to another invoice.',
                        'errorCode' => 'imm',
                    ];
                }

                foreach (array_filter([$response->tranId(), $response->bankTranId()]) as $trxId) {
                    $existing = $this->checkTransaction($trxId);

                    if ($existing['totalresults'] > 0) {
                        // The IPN and the customer's return both land here, so a
                        // payment already on this invoice is not a reused one.
                        if ($this->belongsToInvoice($existing)) {
                            return [
                                'status'  => 'success',
                                'message' => 'The payment has been already recorded.',
                            ];
                        }

                        return [
                            'status'    => 'error',
                            'message'   => 'The transaction has been already used.',
                            'errorCode' => 'tau',
                        ];
                    }
                }

                if ($response->amount() < $this->total) {
                    return [
                        'status'    => 'error',
                        'message'   => 'You\'ve paid less than amount is required.',
                        'errorCode' => 'lpa',
                    ];
                }

                if (! Storage::claim($response->tranId())) {
                    return [
                        'status'  => 'success',
                        'message' => 'The payment is already being recorded.',
                    ];
                }

                $this->logTransaction($response->toArray());

                $trxAddResult = $this->addTransaction($this->resolveTrxId($response));

                if ($trxAddResult['result'] === 'success') {
                    return [
                        'status'  => 'success',
                        'message' => 'The payment has been successfully verified.',
                    ];
                }

                // Let a later attempt, or the IPN, record it instead.
                Storage::release($response->tranId());
            }

            return [
                'status'    => 'error',
                'errorCode' => 'failure',
            ];
        } catch (\Exception $e) {
            return [
                'status'    => 'error',
                'message'   => $e->getMessage(),
                'errorCode' => 'sww',
            ];
        }
    }
}

$sslCommerzCheckout = SSLCommerzCheckout::init();

if (! $sslCommerzCheckout->isActive) {
    die("The gateway is unavailable.");
}

$action = $sslCommerzCheckout->request->get('action');
$invid  = $sslCommerzCheckout->request->get('id');

if ($action === 'init') {
    try {
        $response = $sslCommerzCheckout->createPayment();
        if ($response->success()) {
            $gatewayUrl = $response->gatewayPageURL();

            $forceNewUi = !empty($sslCommerzCheckout->gatewayParams['force_new_ui']);
            $isSandbox  = !empty($sslCommerzCheckout->gatewayParams['sandbox']);

            if ($forceNewUi && !$isSandbox) {
                $rewritten = preg_replace(
                    '#^https?://(?!pay\.sslcommerz\.com/)[a-z0-9-]+\.sslcommerz\.com/#i',
                    'https://pay.sslcommerz.com/',
                    $gatewayUrl
                );

                if ($rewritten !== null) {
                    $gatewayUrl = $rewritten;
                }
            }

            header('Location: ' . $gatewayUrl);
            exit;
        } else {
            redirSystemURL("id=$invid&paymentfailed=true&errorCode={$response->failedReason()}", "viewinvoice.php");
            exit;
        }
    } catch (\Exception $e) {
        redirSystemURL("id=$invid&paymentfailed=true&errorCode=sww", "viewinvoice.php");
        exit;
    }
}

if ($action === 'ipn') {
    $response = $sslCommerzCheckout->handleIpn();

    // SSLCommerz reads the body, not a redirect. Only an unexpected failure
    // gets a 5xx, so that it is the one case SSLCommerz retries.
    if (isset($response['errorCode']) && $response['errorCode'] === 'sww') {
        http_response_code(500);
    }

    header('Content-Type: text/plain');
    echo isset($response['message']) ? $response['message'] : $response['status'];
    exit;
}

if ($action === 'verify') {
    $response = $sslCommerzCheckout->makeTransaction();
    if ($response['status'] === 'success') {
        redirSystemURL("id={$invid}&paymentsuccess=true", "viewinvoice.php");
        exit;
    } else {
        redirSystemURL("id=$invid&paymentfailed=true&errorCode={$response['errorCode']}", "viewinvoice.php");
        exit;
    }
}

if ($action === 'fail') {
    redirSystemURL("id=$invid&paymentfailed=true&errorCode=failure", "viewinvoice.php");
    exit;
}

if ($action === 'cancel') {
    redirSystemURL("id=$invid&paymentfailed=true&errorCode=cancel", "viewinvoice.php");
    exit;
}

redirSystemURL("id=$invid&paymentfailed=true&errorCode=sww", "viewinvoice.php");
