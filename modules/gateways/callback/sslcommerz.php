<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Module\Gateway\Sslcommerz\SSLCommerzAPI;

class SSLCommerzCheckout
{

    private static $instance;

    protected $gatewayModuleName;

    protected $gatewayParams;

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

    public function createPayment()
    {
        $systemUrl   = \WHMCS\Config\Setting::getValue('SystemURL');
        $callbackURL = $systemUrl . '/modules/gateways/callback/' . $this->gatewayModuleName . '.php?id=' . $this->invoice['invoiceid'];

        $fields = [
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

        return $this->sslcommerz->checkout($fields);
    }

    public function makeTransaction()
    {
        try {
            $response = $this->sslcommerz->verify($this->request->get('val_id'));

            if ($response->success()) {
                $existing = $this->checkTransaction($response->bankTranId());

                if ($existing['totalresults'] > 0) {
                    return [
                        'status'    => 'error',
                        'message'   => 'The transaction has been already used.',
                        'errorCode' => 'tau',
                    ];
                }

                if ($response->amount() < $this->total) {
                    return [
                        'status'    => 'error',
                        'message'   => 'You\'ve paid less than amount is required.',
                        'errorCode' => 'lpa',
                    ];
                }

                $this->logTransaction($response->toArray());

                $trxAddResult = $this->addTransaction($response->bankTranId());

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
            header('Location: ' . $response->gatewayPageURL());
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
