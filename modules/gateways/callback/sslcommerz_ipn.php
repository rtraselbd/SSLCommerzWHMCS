<?php

/**
 * IPN listener entry point.
 *
 * The SSLCommerz merchant panel takes a plain URL for its IPN listener, with no
 * query string to carry the action, so this file stands in for
 * callback/sslcommerz.php?action=ipn and hands over to it.
 */

$_GET['action'] = $_REQUEST['action'] = 'ipn';

require __DIR__ . '/sslcommerz.php';
