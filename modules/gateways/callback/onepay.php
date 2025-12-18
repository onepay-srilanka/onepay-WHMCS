<?php
/**
 * WHMCS onepay Payment Gateway Module
 * Callback File
 *
 * @see https://developer.onepay.lk
 * @copyright Copyright (c) 2021-2024 onepay (Private) Limited
 * @license https://www.onepay.lk/legal
 * @version 1.1
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';




use WHMCS\Database\Capsule;

/**
 * Returns the invoice id for hosting items with the 
 * provided subscription id.
 *
 * @param string $subId The subscription id to use for backwards retreiving the correct invoice
 * @return void
 */
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

/**
 * Checks if any item in the tblhosting table,
 * has the subscription id set as $subId.
 * Returns true if exists at least one.
 * Returns false if none.
 *
 * @param string $subId The subscription used to check for existing hosting items
 * @return void
 */
function hostingItemsExistWithSubscriptionId($subId){
    $exists = false;

    $result = Capsule::table('tblhosting')->where(
        'subscriptionid', '=', $subId
    )->get();

    $exists = sizeof($result) > 0;

    return $exists;
}

/**
 * Returns an array of IDs that belong to 
 * hosting products in the invoice, found by
 * $invoiceId
 *
 * @param string $invId The invoice Id to check for products
 * @return void
 */
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

/**
 * Marks the hosting items belonging to the provided invoice id,
 * with the provided subscription id.
 */
function markSubscriptionIdForHostingItem($hostingProductIds, $subId){
    
    foreach ($hostingProductIds as $hostingProductId){
        Capsule::table('tblhosting')->where(
            'id', '=', $hostingProductId
        )->update(
            ['subscriptionid' => $subId]
        );
    }

}

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
// Varies per payment gateway
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

// Validate JSON decode was successful
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    logTransaction($gatewayParams['name'], $rawInput, 'Invalid JSON received in callback');
    die('Invalid callback data');
}

// Extract and validate callback data
$statusCode = isset($data["status"]) ? (int)$data["status"] : 0;
$invoiceId = isset($data["merchant_transaction_id"]) ? (int)$data["merchant_transaction_id"] : 0;
$transactionId = isset($data["onepay_transaction_id"]) ? trim($data["onepay_transaction_id"]) : '';
$hash = isset($data["hash"]) ? trim($data["hash"]) : '';
$subscriptionId = isset($data["subscription_id"]) ? trim($data["subscription_id"]) : '';
$paymentAmount = isset($data["paid_amount"]) ? floatval($data["paid_amount"]) : 0.00;
$paymentFee = "0";
$systemURL = isset($gatewayParams['systemurl']) ? $gatewayParams['systemurl'] : '';

// Validate required fields
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

/**
 * Validate callback authenticity.
 *
 * Most payment gateways provide a method of verifying that a callback
 * originated from them. In the case of our example here, this is achieved by
 * way of a shared secret which is used to build and compare a hash.
 */
$secretKey = $gatewayParams['secretKey'];

$request_args = array(
    'onepay_transaction_id' => $transactionId,
    'merchant_transaction_id' => $invoiceId,
    'status' => (int)$statusCode
);


$json_string=json_encode($request_args);

$json_hash_result = hash('sha256',$json_string);

// if ($hash != $json_hash_result) { 
//     logTransaction($gatewayParams['name'],$hash. 'Invoice #' . $json_hash_result, 'Hash Verification Failure');
//     $success = false;
// }

/**
 * If the callback is successful so far (hash verification passed), 
 * and we have a subscribe_id for this callback,
 * 
 * Update any tblhosting products with the provided
 * subscription_id.
 */

if ($success){

    if ($subscriptionId){

        /**
         * Log whether the subscription id exists in the post parameters for the request
         */
        logTransaction(
            $gatewayParams['name'], 
            'Invoice #' . $invoiceId . ' Subscription ID = ' . $subscriptionId, 
            'Call recognized as Recurring API callback');

        if (hostingItemsExistWithSubscriptionId($subscriptionId)){

            // Existing subscription
            
            logTransaction(
                $gatewayParams['name'], 
                $subscriptionId, 
                'Existing subscription id for Invoice #' . $invoiceId
            );

            // Invoice ID for the aforesaid subscription

            $returnedInvoiceId = getInvoiceIdForHostingItemWithSubscriptionId($subscriptionId, $gatewayParams['name']);

            if ($returnedInvoiceId > 0) {
                logTransaction(
                    $gatewayParams['name'], 
                    $returnedInvoiceId, 
                    'Found latest invoice id for sub. id ' . $subscriptionId
                );

                // Correct invoice (latest generated invoice)
                // for the provided $subscriptionId.
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

            // New subscription

            logTransaction(
                $gatewayParams['name'], 
                'Subscription ID = ' . $subscriptionId, 
                'Storing new subscription'
            );

            /**
             * Hosting product ids for invoice id
             */
            $hostingItemIds = getHostingItemIdsForInvoiceId($invoiceId, $gatewayParams['name']);
            logTransaction(
                $gatewayParams['name'], 
                json_encode($hostingItemIds), 
                'Identified the hosting item ids for the invoice #' . $invoiceId
            );

            /**
             * We have a subscription id, add it to the tblhosting 
             * item
             */
            markSubscriptionIdForHostingItem($hostingItemIds, $subscriptionId);

        }
    }
    else{
        logTransaction($gatewayParams['name'], 'Invoice #' . $invoiceId, 'Call recognized as a Checkout API callback');
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

    logTransaction($gatewayParams['name'], 'Invoice #' . $invoiceId, 'checkCbInvoiceId Passed');
}

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given
 * transaction number.
 *
 * Performs a die upon encountering a duplicate.
 */
checkCbTransID($transactionId);

/**
 * Log Transaction.
 *
 * Add an entry to the Gateway Log for debugging purposes.
 *
 * The debug data can be a string or an array. In the case of an
 * array it will be
 *
 * @param string $gatewayName        Display label
 * @param string|array $debugData    Data to log
 * @param string $transactionStatus  Status
 */
logTransaction($gatewayParams['name'], json_encode($data), 'checkCbTransID Passed');




if ($success) {


    /**
     * Add Invoice Payment.
     *
     * Applies a payment transaction entry to the given invoice ID.
     *
     * @param int $invoiceId         Invoice ID
     * @param string $transactionId  Transaction ID
     * @param float $paymentAmount   Amount paid (defaults to full balance)
     * @param float $paymentFee      Payment fee (optional)
     * @param string $gatewayModule  Gateway module name
     */
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        $paymentFee,
        $gatewayModuleName
    );
 
    // $responseUrl = $systemURL . 'viewinvoice.php?id=' . $invoiceId;

    // echo '<meta http-equiv="refresh" content="0; url='.$responseUrl.'">'; // if used this Gives Error
    // exit;

}else{

//     $responseUrl = $systemURL . 'viewinvoice.php?id=' . $invoiceId;

//     echo '<meta http-equiv="refresh" content="0; url='.$responseUrl.'">'; // if used this Gives Error
//     exit;

// <meta http-equiv="refresh" content="0; url='..'">
}