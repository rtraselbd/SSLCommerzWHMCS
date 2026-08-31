<?php

namespace WHMCS\Module\Gateway\Sslcommerz;

require_once __DIR__ . '/vendor/autoload.php';

use Exception;
use GuzzleHttp\Client;
use SSLCommerz\Exception\SSLCommerzException;
use SSLCommerz\SSLCommerz;

class SSLCommerzAPI
{
    private SSLCommerz $sslcommerz;

    private array $credential;

    public function __construct(array $credential)
    {
        $this->credential = $credential;
        $this->sslcommerz = new SSLCommerz($credential['store_id'], $credential['store_password'], $credential['sandbox']);
    }

    public function checkout($fields)
    {
        try {
            $params = (new CheckoutParams())
                ->setAmount($fields['amount']) // Amount in BDT
                ->setCurrency('BDT')
                ->setTransactionId($fields['tran_id']) // Unique transaction ID
                ->setSuccessUrl($fields['callback_url'] . '&action=verify')
                ->setFailUrl($fields['callback_url'] . '&action=fail')
                ->setCancelUrl($fields['callback_url'] . '&action=cancel')
                ->setIpnUrl($fields['callback_url'] . '&action=ipn')
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

    public function refund(string $bankTrxId, $amount)
    {
        try {
            return $this->sslcommerz->refundPayment($bankTrxId, $amount, 'The customer decided not to proceed with hosting or domain registration');
        } catch (SSLCommerzException $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Check the signature SSLCommerz sends with an IPN notification.
     *
     * Returns null when the notification carries nothing to check against, in
     * which case the caller still has the validation API as the authority.
     *
     * @see https://github.com/sslcommerz/SSLCommerz-PHP lib/SslCommerzNotification.php
     */
    public function signatureMatches(array $payload)
    {
        if (empty($payload['verify_sign']) || empty($payload['verify_key'])) {
            return null;
        }

        $fields = [];

        foreach (explode(',', $payload['verify_key']) as $field) {
            if (isset($payload[$field])) {
                $fields[$field] = $payload[$field];
            }
        }

        $fields['store_passwd'] = md5($this->credential['store_password']);

        ksort($fields);

        $hash = '';

        foreach ($fields as $field => $value) {
            $hash .= $field . '=' . $value . '&';
        }

        return md5(rtrim($hash, '&')) === $payload['verify_sign'];
    }

    /**
     * Every attempt SSLCommerz holds against a merchant transaction id.
     */
    public function transaction(string $trxId)
    {
        $response = $this->query(['tran_id' => $trxId]);

        return $response['element'] ?? [];
    }

    /**
     * Resolve the bank transaction id the refund API requires from a merchant
     * transaction id.
     */
    public function bankTrxId(string $trxId)
    {
        foreach ($this->transaction($trxId) as $attempt) {
            $status = isset($attempt['status']) ? strtolower($attempt['status']) : '';

            if (! empty($attempt['bank_tran_id']) && ($status === 'valid' || $status === 'validated')) {
                return $attempt['bank_tran_id'];
            }
        }

        return null;
    }

    /**
     * The transaction query API shares its endpoint with the refund API, so it
     * is called directly instead of through the library.
     */
    private function query(array $params)
    {
        $baseUrl = empty($this->credential['sandbox'])
            ? 'https://securepay.sslcommerz.com/'
            : 'https://sandbox.sslcommerz.com/';

        try {
            $response = (new Client(['base_uri' => $baseUrl, 'timeout' => 10]))
                ->request('GET', 'validator/api/merchantTransIDvalidationAPI.php', [
                    'query' => array_merge([
                        'store_id'     => $this->credential['store_id'],
                        'store_passwd' => $this->credential['store_password'],
                        'format'       => 'json',
                        'v'            => 1,
                    ], $params),
                ]);

            $data = json_decode((string) $response->getBody(), true);

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            throw new Exception('Transaction query failed: ' . $e->getMessage());
        }
    }
}
