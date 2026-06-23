<?php
/**
 * Patched by HostGrap Technologies - Vidurath Jayaweera
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

function getInvoiceIdForHostingItemWithSubscriptionId($subId, $gatewayName = 'onepay'){
    $returningInvoiceId = 0;

    if (empty($subId)) {
        return $returningInvoiceId;
    }

    $hostingItemWithSubId = Capsule::table('tblhosting')->where(
        'subscriptionid', '=', $subId
    )->orderBy('id', 'DESC')->first();

    if ($hostingItemWithSubId == null) {
        logTransaction(
            $gatewayName,
            'Subscription ID = ' . $subId,
            'No hosting items found with the provided subscription ID.'
        );
        return $returningInvoiceId;
    }

    $invoiceItemForHostingItem = Capsule::table('tblinvoiceitems')->where('relid', '=', $hostingItemWithSubId->id)
                                                                  ->where('type', '=', 'Hosting')
                                                                  ->first();

    if ($invoiceItemForHostingItem == null){
        logTransaction(
            $gatewayName,
            'Subscription ID = ' . $subId,
            'There were no hosting items purchased that has stored the subscription id. This indicates a fatal error. Please contact onepay support and state this error.'
        );
        return $returningInvoiceId;
    }

    $returningInvoiceId = $invoiceItemForHostingItem->invoiceid;

    return $returningInvoiceId;
}

function hostingItemsExistWithSubscriptionId($subId){
    $exists = false;

    $result = Capsule::table('tblhosting')->where(
        'subscriptionid', '=', $subId
    )->get();

    $exists = sizeof($result) > 0;

    return $exists;
}

function getHostingItemIdsForInvoiceId($invId, $gatewayName = 'onepay'){

    $hostingItemIds = array();

    if (empty($invId) || $invId <= 0) {
        return $hostingItemIds;
    }

    $hostingItems = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invId)
                                                     ->where('type', '=', 'Hosting')
                                                     ->get();

    if ($hostingItems != null){
        foreach ($hostingItems as $hostingItem){
            if (isset($hostingItem->relid)) {
                $hostingItemIds[] = $hostingItem->relid;
            }
        }
    }

    if (count($hostingItemIds) == 0){
        logTransaction(
            $gatewayName,
            'Invoice #' . $invId,
            'Number of products in invoice was zero or null. This indicates a fatal error, please contact onepay support and state this error.'
        );
    }

    return $hostingItemIds;

}

function markSubscriptionIdForHostingItem($hostingProductIds, $subId){

    foreach ($hostingProductIds as $hostingProductId){
        Capsule::table('tblhosting')->where(
            'id', '=', $hostingProductId
        )->update(
            ['subscriptionid' => $subId]
        );
    }

}

$gatewayModuleName = basename(__FILE__, '.php');

$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    logTransaction($gatewayParams['name'], $rawInput, 'Invalid JSON received in callback');
    die('Invalid callback data');
}

$statusCode = isset($data["status"]) ? (int)$data["status"] : 0;
$invoiceIdRaw = isset($data["merchant_transaction_id"]) ? trim((string)$data["merchant_transaction_id"]) : '';
$invoiceId = isset($data["merchant_transaction_id"]) ? (int)$data["merchant_transaction_id"] : 0;
$transactionId = isset($data["onepay_transaction_id"]) ? trim($data["onepay_transaction_id"]) : '';
$hash = isset($data["hash"]) ? trim($data["hash"]) : '';
$subscriptionId = isset($data["subscription_id"]) ? trim($data["subscription_id"]) : '';
$paymentAmount = isset($data["paid_amount"]) ? floatval($data["paid_amount"]) : 0.00;
$paymentFee = "0";
$systemURL = isset($gatewayParams['systemurl']) ? $gatewayParams['systemurl'] : '';

if (empty($invoiceId) || empty($transactionId)) {
    logTransaction($gatewayParams['name'], json_encode($data), 'Missing required callback parameters');
    die('Invalid callback data');
}

logTransaction($gatewayParams['name'], '', 'Initiating callback process. Payment notification received by onepay Callback File.');

if($statusCode == 1){
    $success = true;
    logTransaction($gatewayParams['name'], 'Invoice #' . $invoiceId, 'Status code is success');
}
else {
    $success = false;
    logTransaction($gatewayParams['name'], 'Invoice #' . $invoiceId . ' Status code = ' . $statusCode , 'Status code is not success');
}

$secretKey = $gatewayParams['secretKey'];

$request_args = array(
    'onepay_transaction_id' => $transactionId,
    'merchant_transaction_id' => $invoiceIdRaw,
    'app_id' => $gatewayParams['appID'],
    'hash_salt' => $secretKey,
    'status' => (int)$statusCode
);

$json_string = json_encode($request_args);

$json_hash_result = hash('sha256', $json_string);

if (!hash_equals($json_hash_result, $hash)) {
    logTransaction($gatewayParams['name'], $hash . 'Invoice #' . $json_hash_result, 'Hash Verification Failure');
    $success = false;
}

if ($success){

    if ($subscriptionId){

        logTransaction(
            $gatewayParams['name'],
            'Invoice #' . $invoiceId . ' Subscription ID = ' . $subscriptionId,
            'Call recognized as Recurring API callback');

        if (hostingItemsExistWithSubscriptionId($subscriptionId)){

            logTransaction(
                $gatewayParams['name'],
                $subscriptionId,
                'Existing subscription id for Invoice #' . $invoiceId
            );

            $returnedInvoiceId = getInvoiceIdForHostingItemWithSubscriptionId($subscriptionId, $gatewayParams['name']);

            if ($returnedInvoiceId > 0) {
                logTransaction(
                    $gatewayParams['name'],
                    $returnedInvoiceId,
                    'Found latest invoice id for sub. id ' . $subscriptionId
                );

                $invoiceId = $returnedInvoiceId;
            } else {
                logTransaction(
                    $gatewayParams['name'],
                    'Subscription ID = ' . $subscriptionId,
                    'Could not find invoice for existing subscription'
                );
            }

        }
        else{

            logTransaction(
                $gatewayParams['name'],
                'Subscription ID = ' . $subscriptionId,
                'Storing new subscription'
            );

            $hostingItemIds = getHostingItemIdsForInvoiceId($invoiceId, $gatewayParams['name']);
            logTransaction(
                $gatewayParams['name'],
                json_encode($hostingItemIds),
                'Identified the hosting item ids for the invoice #' . $invoiceId
            );

            markSubscriptionIdForHostingItem($hostingItemIds, $subscriptionId);

        }
    }
    else{
        logTransaction($gatewayParams['name'], 'Invoice #' . $invoiceId, 'Call recognized as a Checkout API callback');
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

    logTransaction($gatewayParams['name'], 'Invoice #' . $invoiceId, 'checkCbInvoiceId Passed');
}

checkCbTransID($transactionId);

logTransaction($gatewayParams['name'], json_encode($data), 'checkCbTransID Passed');

if ($success) {

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        $paymentFee,
        $gatewayModuleName
    );

}
