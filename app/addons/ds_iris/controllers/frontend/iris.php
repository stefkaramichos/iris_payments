<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;

if ($mode === 'iris_return') {
    $order_id = !empty($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;

    if (!$order_id) {
        fn_set_notification('E', __('error'), __('order_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, "checkout.complete"];
    }

    // Load order to get stored payment info
    $order_info = fn_get_order_info($order_id);
    $pi = !empty($order_info['payment_info']) ? $order_info['payment_info'] : [];

    $iris_order_id   = !empty($pi['iris_order_id'])   ? $pi['iris_order_id']   : null;
    $message_id      = !empty($pi['iris_message_id']) ? $pi['iris_message_id'] : null;
    $creation_dt     = !empty($pi['iris_creation_dt'])? $pi['iris_creation_dt']: gmdate("Y-m-d\TH:i:s\Z");

    if (!$iris_order_id || !$message_id) {
        error_log("IRIS Return: Missing identifiers for order #{$order_id}");
        // No identifiers -> can't verify; show complete page without changing status
        return [CONTROLLER_STATUS_REDIRECT, "checkout.complete&order_id={$order_id}"];
    }

    $username = Registry::get('addons.ds_iris.username');
    $password = Registry::get('addons.ds_iris.password');

    $status_url = 'https://iris.dias.com.gr/iris-ecommerce-msp/api/get-order-status';

    $payload = [
        "userName"          => $username,
        "password"          => $password,
        "messageId"         => $message_id,
        "creationDateTime"  => $creation_dt,
        "orderId"           => $iris_order_id,
        "version"           => "2"
    ];

    // Log request (mask password)
    $log_payload = $payload;
    $log_payload['password'] = str_repeat('*', 8);
    error_log("IRIS Status Request for order #{$order_id}: " . json_encode($log_payload));

    // Request status
    $ch = curl_init($status_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    $response   = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("IRIS Status Raw Response for order #{$order_id}: HTTP {$http_code} - {$response}");
    if (!empty($curl_error)) {
        error_log("IRIS Status cURL Error for order #{$order_id}: " . $curl_error);
    }

    $eligible = false;
    if ($http_code == 200 && !empty($response)) {
        $result = json_decode($response, true);
        error_log("IRIS Status Parsed Response for order #{$order_id}: " . print_r($result, true));

        $status = isset($result['resp']['txStatus']) ? $result['resp']['txStatus'] : '';
        // Only mark 'O' if IRIS says TRANSACTION_CREATED
        if ($status === 'AUTHORISED') {
            $eligible = true;
        } else {
            error_log("IRIS Status for order #{$order_id} is '{$status}', not marking as O.");
        }
    } else {
        error_log("IRIS Status HTTP error for order #{$order_id}");
    }

    if ($eligible) {
        // Change status to 'O'
        fn_change_order_status($order_id, 'O');
        error_log("IRIS Order #{$order_id} status changed to O after verification.");
        return [CONTROLLER_STATUS_REDIRECT, "checkout.complete&order_id={$order_id}"];
    }

    // Always show the checkout complete page
}
