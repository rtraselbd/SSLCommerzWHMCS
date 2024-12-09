<?php

namespace WHMCS\Module\Gateway\Sslcommerz;

require_once __DIR__ . '/vendor/autoload.php';

use Exception;
use SSLCommerz\Exception\SSLCommerzException;
use SSLCommerz\PaymentParams;
use SSLCommerz\SSLCommerz;

class SSLCommerzAPI
{
    private SSLCommerz $sslcommerz;

    public function __construct(array $credential)
    {
        $this->sslcommerz = new SSLCommerz($credential['store_id'], $credential['store_password'], $credential['sandbox']);
    }

    public function checkout($fields)
    {
        try {
            $params = (new PaymentParams())
                ->setAmount($fields['amount']) // Amount in BDT
                ->setCurrency('BDT')
                ->setTransactionId(uniqid()) // Unique transaction ID
                ->setSuccessUrl($fields['callback_url'] . '&action=verify')
                ->setFailUrl($fields['callback_url'] . '&action=fail')
                ->setCancelUrl($fields['callback_url'] . '&action=cancel')
                ->setCustomerInfo($fields['name'], $fields['email'], $fields['phone'], $fields['address'], $fields['city'], $fields['country'])
                ->setProductInfo('Domain & Hosting', 'Domain-Hosting', 'general')
                ->setCustomValues($fields['invoice_id']);

            return $this->sslcommerz->initiatePayment($params);
        } catch (SSLCommerzException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function verify(string $val_id = null)
    {
        try {
            return $this->sslcommerz->validatePayment($val_id);
        } catch (SSLCommerzException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function refund(string $trxId, $amount)
    {
        try {
            return $this->sslcommerz->refundPayment($trxId, $amount, 'The customer decided not to proceed with hosting or domain registration');
        } catch (SSLCommerzException $e) {
            throw new Exception($e->getMessage());
        }
    }
}
