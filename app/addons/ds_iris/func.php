<?php
use Tygh\Registry;
if (!defined('BOOTSTRAP')) { die('Access denied'); }



function fn_ds_iris_place_order(&$order_id, &$action, &$order_status, &$cart, &$auth)
{
    if (empty($order_id)) {
        return;
    }

    if (defined('AREA') && AREA === 'A') {
        return;
    }

    $debug_mode = Registry::get('addons.ds_iris.debug_mode');

    $order_info = fn_get_order_info($order_id);
    $payment_id = Registry::get('addons.ds_iris.payment_id');

    if (empty($order_info['payment_method']['payment_id']) || $order_info['payment_method']['payment_id'] != $payment_id) {
        return;
    }

    // Step 1: API URL
    $request_url = 'https://iris.dias.com.gr/iris-ecommerce-msp/api/register-order-request';

    // Credentials from addon settings
    $username = Registry::get('addons.ds_iris.username');
    $password = Registry::get('addons.ds_iris.password');

    // Step 2: Prepare payload
    $message_id     = 'MSG-' . $order_id . '-' . time();
    $initiating_ref = 'REF-' . $order_id . '-' . time();
    $creation_date  = gmdate("Y-m-d\TH:i:s\Z");

    // Use store/base currency if needed; fallback to order currency
    $currency = !empty($order_info['secondary_currency']) ? $order_info['secondary_currency'] : $order_info['currency'];

    $payload = [
        "userName"                 => $username,
        "password"                 => $password,
        "messageId"                => $message_id,
        "creationDateTime"         => $creation_date,
        "initiatingPartyRefId"     => $initiating_ref,
        // IMPORTANT: return to iris_return so we can verify status first
        "initiatingPartyReturnURL" => fn_url("index.php?dispatch=iris.iris_return&order_id={$order_id}", 'C'),
        // If IRIS expects cents as integer, keep *100 and no decimals; if not, use the rounded decimal.
        // Below assumes IRIS expects amount as string of decimal units (e.g., "30.00"). Adjust if needed.
        "instructedAmount"         => (string) round($order_info['total'] * 100, 2),
        "currency"                 => $currency,
        "remmittanceInfo"          => [
            "unstructured1" => "Πώληση προϊόντος",
            "unstructured2" => "Order ID " . $order_id
        ],
        "language"                 => CART_LANGUAGE,
        "initiationChannel"        => "1"
    ];

    // Log request (mask password)
    $log_payload = $payload;
    $log_payload['password'] = str_repeat('*', 8);

    if ($debug_mode === 'Y') {
        error_log("IRIS Request Payload for order #{$order_id}: " . json_encode($log_payload));
    }
    // Step 3: Send request
    $ch = curl_init($request_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    // (optional) timeouts
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    $response   = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($debug_mode === 'Y') {
        // Log raw response + HTTP status
        error_log("IRIS Raw Response for order #{$order_id}: HTTP {$http_code} - {$response}");
    }
    if (!empty($curl_error)) {
        error_log("IRIS cURL Error for order #{$order_id}: " . $curl_error);
    }

    // Step 4: Handle response
    if ($http_code == 200 && !empty($response)) {
        $result   = json_decode($response, true);
        $bank_url = isset($result['resp']['bankSelectionToolUrl']) ? $result['resp']['bankSelectionToolUrl'] : null;

        if ($debug_mode === 'Y') {
            // Log parsed response
            error_log("IRIS Parsed Response for order #{$order_id}: " . print_r($result, true));
        }

        // Persist identifiers for status query on return
        $iris_order_id      = isset($result['resp']['orderId']) ? $result['resp']['orderId'] : null;
        $iris_message_id    = isset($result['messageId']) ? $result['messageId'] : $message_id;
        $iris_created_at    = isset($result['creationDateTime']) ? $result['creationDateTime'] : $creation_date;

        $payment_info = [
            'iris_order_id'        => $iris_order_id,
            'iris_message_id'      => $iris_message_id,
            'iris_creation_dt'     => $iris_created_at,
            'iris_initiating_ref'  => $initiating_ref,
            'iris_return_url'      => "index.php?dispatch=iris.iris_return&order_id={$order_id}",
        ];
        fn_update_order_payment_info($order_id, $payment_info);

        if (!empty($bank_url)) {
            error_log("IRIS Redirect for order #{$order_id} -> {$bank_url}");
            fn_redirect($bank_url, true);
        } else {
            error_log("IRIS Missing bankSelectionToolUrl for order #{$order_id}");
            fn_set_notification('E', __('error'), __('iris_payment_error'));
        }
    } else {
        fn_set_notification('E', __('error'), __('iris_payment_error_connection'));
    }
}
