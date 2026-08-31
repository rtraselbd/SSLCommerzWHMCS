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
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id'),
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
            'invoice_id' => $this->invoice['invoiceid'],
            'tran_id'    => $trxId,
            'amount'     => $this->total,
            'currency'   => 'BDT',
        ]);

        return $this->sslcommerz->checkout($fields);
    }

    public function makeTransaction()
    {
        try {
            $response = $this->sslcommerz->verify($this->request->get('val_id'));

            if ($response->success()) {
                $this->recordTransaction($response);

                if (! empty($response->valueA()) && (int) $response->valueA() !== (int) $this->invoice['invoiceid']) {
                    return [
                        'status'    => 'error',
                        'message'   => 'The transaction belongs to another invoice.',
                        'errorCode' => 'imm',
                    ];
                }

                foreach (array_filter([$response->tranId(), $response->bankTranId()]) as $trxId) {
                    $existing = $this->checkTransaction($trxId);

                    if ($existing['totalresults'] > 0) {
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

                $this->logTransaction($response->toArray());

                $trxAddResult = $this->addTransaction($this->resolveTrxId($response));

                if ($trxAddResult['result'] === 'success') {
                    return [
                        'status'  => 'success',
                        'message' => 'The payment has been successfully verified.',
                    ];
                }
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
