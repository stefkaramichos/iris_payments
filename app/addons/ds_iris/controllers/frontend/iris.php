<?php

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'iris_return') {
    $order_id = !empty($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;

    if ($order_id) {
        // Update order status to "O"
        fn_change_order_status($order_id, 'O');

        error_log("IRIS Order #{$order_id} status changed to O on return.");

        // Redirect to checkout complete page
        return [CONTROLLER_STATUS_REDIRECT, "checkout.complete&order_id={$order_id}"];
    }
}
