<?php

namespace WHMCS\Module\Gateway\Sslcommerz;

require_once __DIR__ . '/vendor/autoload.php';

use SSLCommerz\PaymentParams;

/**
 * The bundled library has no setter for the IPN URL and keeps its parameters
 * private, so the extra field is carried here instead of patching vendor code.
 */
class CheckoutParams extends PaymentParams
{
    private array $extra = [];

    public function setIpnUrl($url)
    {
        $this->extra['ipn_url'] = $url;

        return $this;
    }

    public function getParams()
    {
        return array_merge(parent::getParams(), $this->extra);
    }
}
