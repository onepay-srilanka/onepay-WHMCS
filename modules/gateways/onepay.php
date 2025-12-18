<?php
/**
 * WHMCS onepay Payment Gateway Module
 *
 * @see https://onepay.lk
 * @copyright Copyright (c) 2021-2024 onepay (Private) Limited
 * @license https://privacy-onepay.spemai.com/
 * @version 1.1
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.d
 *
 * @see http://docs.whmcs.com/Gateway_Module_Meta_Data_Parameters
 *
 * @return array
 */
function onepay_MetaData()
{
    return array(
        'DisplayName' => 'onepay Payment Gateway Module',
        'APIVersion' => '1.1',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
        'Description' => 'Accept payments via Visa, MasterCard, AMEX, and Lanka QR. Supports both one-time and recurring subscription payments. Secure payment processing powered by onepay.',
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */

use WHMCS\Database\Capsule;

require 'onepay/taxCalculation.php'; // <- need this
require 'onepay/classdefinitionsFile.php'; // <- need this
require 'onepay/getconsumableproductsFile.php'; // <- need this for 'getRecurringInfoForBillingCycle_onepay'
require 'onepay/recurringhelpersFile.php'; // <- need this

function onepay_config()
{
    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'onepay',
        ),
        // a text field type allows for single line text input
        'appID' => array(
            'FriendlyName' => 'App ID',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Your onepay App ID',
        ),
        // a password field type allows for masked text input
        'secretKey' => array(
            'FriendlyName' => 'Hash Salt',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Hash salt key you set in your onepay Account',
        ),

        'authKey' => array(
            'FriendlyName' => 'App token',
            'Type' => 'text',
            'Size' => '100',
            'Default' => '',
            'Description' => 'App token you set in your onepay Account',
        ),

        // how to handle subscriptions
        'subscriptionsProcessedAs' => array(
            'FriendlyName' => 'Subscription charging options',
            'Type' => 'dropdown',
            'Options' => array(
                'option1' => 'One-time (Pay with onepay)',
                'option2' => 'Recurring (Subscribe with onepay)',
                'option3' => 'Both options'
            ),
            'Description' => 'Select how subscriptions should be charged',
            'Default' => 'option3'
        )
    );
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return string
 */
function onepay_link($params)
{
    // Gateway Configuration Parameters
    $appId = isset($params['appID']) ? trim($params['appID']) : '';
    $secretKey = isset($params['secretKey']) ? trim($params['secretKey']) : '';
    $authKey = isset($params['authKey']) ? trim($params['authKey']) : '';
    $subscriptionsProcessedAs = isset($params['subscriptionsProcessedAs']) ? $params['subscriptionsProcessedAs'] : 'option3';

    // Validate required configuration
    if (empty($appId) || empty($secretKey) || empty($authKey)) {
        return '<div class="alert alert-danger">Payment gateway configuration error. Please contact support.</div>';
    }

    // Invoice Parameters
    $invoiceId = isset($params['invoiceid']) ? (int)$params['invoiceid'] : 0;
    $description = isset($params['description']) ? $params['description'] : '';
    $amount = isset($params['amount']) ? sprintf("%.2f", floatval($params['amount'])) : "0.00";
    $currencyCode = isset($params['currency']) ? $params['currency'] : '';

    // Client Parameters
    $firstname = isset($params['clientdetails']['firstname']) ? trim($params['clientdetails']['firstname']) : '';
    $lastname = isset($params['clientdetails']['lastname']) ? trim($params['clientdetails']['lastname']) : '';
    $email = isset($params['clientdetails']['email']) ? trim($params['clientdetails']['email']) : '';
    $address1 = isset($params['clientdetails']['address1']) ? trim($params['clientdetails']['address1']) : '';
    $address2 = isset($params['clientdetails']['address2']) ? trim($params['clientdetails']['address2']) : '';
    $city = isset($params['clientdetails']['city']) ? trim($params['clientdetails']['city']) : '';
    $state = isset($params['clientdetails']['state']) ? trim($params['clientdetails']['state']) : '';
    $postcode = isset($params['clientdetails']['postcode']) ? trim($params['clientdetails']['postcode']) : '';
    $country = isset($params['clientdetails']['country']) ? trim($params['clientdetails']['country']) : '';
    $phone = isset($params['clientdetails']['phonenumber']) ? trim($params['clientdetails']['phonenumber']) : '';

    // System Parameters
    $companyName = isset($params['companyname']) ? $params['companyname'] : '';
    $systemUrl = isset($params['systemurl']) ? rtrim($params['systemurl'], '/') : '';
    $returnUrl = isset($params['returnurl']) ? $params['returnurl'] : '';
    $langPayNow = isset($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';
    $moduleDisplayName = isset($params['name']) ? $params['name'] : 'onepay';
    $moduleName = isset($params['paymentmethod']) ? $params['paymentmethod'] : 'onepay';
    $whmcsVersion = isset($params['whmcsVersion']) ? $params['whmcsVersion'] : '';

    // API Endpoint
    $url = 'https://merchant-api-live-v2.onepay.lk/api/ipg/gateway/whmc/';

    // Backup of the original form
    // since we need one for the "Pay Now"
    // button when Recurring payments
    // are available
    $backupfields = array();

    // Ensure WHMCS database connection is available
    if (!class_exists('WHMCS\Database\Capsule')) {
        require_once __DIR__ . '/../../../init.php';
    }

    // Prepare base post fields
    $postfields = array();
    $postfields['app_id'] = $appId;
    $postfields['transaction_callback_url'] = $systemUrl . '/modules/gateways/callback/' . $moduleName . '.php';
    $postfields['transaction_redirect_url'] = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
    $postfields['customer_first_name'] = $firstname;
    $postfields['customer_last_name'] = $lastname;
    $postfields['customer_email'] = $email;
    $postfields['customer_phone_number'] = $phone;
    $postfields['reference'] = (string)$invoiceId;
    $postfields['currency'] = $currencyCode;
    $postfields['callback_authorization'] = "not";
    $postfields['authorization'] = $authKey;
    $postfields['is_sdk'] = 1;
    $postfields['sdk_type'] = "WHMCS";


    // The final HTML that is returned
    $htmlOutput = "";

    // Whether the recurring API can be called based
    // on the 'Subscriptions processed as' parameter
    $canCallRecurringApiIfNeeded = false;

    // Whether the invoice actually
    // contains recurring products
    $invoiceContainsRecurringItems = false;

    // Can we call the recurring api even
    // if we wanted to?
    if ($subscriptionsProcessedAs == 'option2' || 
        $subscriptionsProcessedAs == 'option3' ||
        $subscriptionsProcessedAs == '' ||
        $subscriptionsProcessedAs == null){
        $canCallRecurringApiIfNeeded = true;
    }

    // Does the invoice contain recurring
    // items?
    $firstRecurringProduct = getFirstRecurringProductOnepay($invoiceId);
    $invoiceContainsRecurringItems = $firstRecurringProduct != null;

    // Log the notify_url
    do_log_onepay("Notify URL = " . $postfields['notify_url']);

    $requestData=[];

    // Add the final payment details to the postfields array
    if ($invoiceContainsRecurringItems && $canCallRecurringApiIfNeeded){

        // Backup existing post fields
        foreach ($postfields as $k => $v){
            $backupfields[$k] = $v;
        }

        /**
         * The startup and recurrence total for the given invoiceid
         * @var onepayPriceData
         */
        $result = getStartupAndRecurrenceTotalForInvoiceWithFirstProductOnepay(
            $invoiceId, 
            $firstRecurringProduct
        );
        
        // Change the existing postfields array to
        // allow to call the recurring API
        $postfields['type'] = "RECURRING";
        $postfields['recurring_amount'] =  number_format(floatval($result->recurringTotal),2,'.','');
        $postfields['amount'] = number_format(floatval($result->startupTotal + $result->recurringTotal),2,'.','');
        $postfields['rec_type'] = $firstRecurringProduct->recurringPeriod;
        $postfields['duration'] = $firstRecurringProduct->recurringDuration;

        if($firstRecurringProduct->cycle>0)
        {
            $postfields['cycles'] = $firstRecurringProduct->cycle - 1;
        }else{
            $postfields['cycles'] = $firstRecurringProduct->cycle;
        }
        

        // $postfields['item_name_1'] = 'Recurring Total';
        // $postfields['amount_1'] = $result->recurringTotal;
        // $postfields['quantity_1'] = "1"; 
        // $postfields['item_number_1'] = $description;
    }
    else{

        $postfields['amount'] =  sprintf("%.2f",$amount);
        $postfields['type'] = "ONETIME";
        $postfields['duration'] = true;


        do_log_onepay('OP: Not using recurring payments');
        // $postfields['item_name_1'] = $description;
        // $postfields['amount_1'] = $amount;
        // $postfields['quantity_1'] = "1"; 
        // $postfields['item_number_1'] = $invoiceId;



    }
    
    // Add tax 
    $taxAmount = getTaxForInvoiceOnepay($invoiceId);
    
    if ($invoiceContainsRecurringItems && $canCallRecurringApiIfNeeded){
        // DEBUG tax amount
        do_log_onepay('OP: Adding a tax of ' . $taxAmount);
        // $postfields['amount_1'] = $postfields['amount_1'] + $taxAmount;number_format(floatval($taxAmount),2,'.','');
        $postfields['amount'] = number_format(floatval($taxAmount + $postfields['amount']),2,'.','');
        do_log_onepay('OP: Final ST = ' . $postfields['startup_fee'] . ' REC = ' . $postfields['amount'] . ' Recurs every ' . $postfields['recurrence'] . ' for ' . $postfields['duration']);
    }
    else{
        do_log_onepay('OP: Tax is included in the invoice. Tax = ' . $taxAmount);
        do_log_onepay('OP: Final AMT = ' . $postfields['amount']);
    }

    // Create the Forms

    if ($invoiceContainsRecurringItems && $canCallRecurringApiIfNeeded){

        // If no selection is done at the configuration for the
        // 'Process Subscriptions processed as' setting,
        // Subscription payments will by default have both 'Pay' and 'Subscribe'
        // buttons enabled.

        $defaultedToBothOptions = false;

        if ($subscriptionsProcessedAs == null || $subscriptionsProcessedAs == ''){
            $defaultedToBothOptions = true;
        }

        $htmlOutput .= '<table style="border-collapse: separate; border-spacing: 15px 0;"><tbody><tr style="text-align: center;"><td>';

        // The 'Subscribe' Form
        if ($subscriptionsProcessedAs == 'option2' || 
            $subscriptionsProcessedAs == 'option3' ||
            $defaultedToBothOptions){

                $requestData = array(
                    "transaction_redirect_url" => $systemUrl. 'viewinvoice.php?id=' . $invoiceId,
                    "customer_email" => $email,
                    "customer_phone_number" => $phone,
                    "reference" => (string)$invoiceId,
                    "amount" => number_format(floatval($postfields['amount']),2,'.',''),
                    "app_id" =>  $appId,            
                    'is_sdk' => "1",
                    'sdk_type' => "WHMCS",
                    'authorization' => $authKey,
                );

                $result_body = json_encode($requestData,JSON_UNESCAPED_SLASHES);
                $result_body .= $secretKey;
                $hash_result = hash('sha256',$result_body);

    

                $url .= "?hash=$hash_result";
                
            $htmlOutput .= '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="margin-bottom: 0;">';
            foreach ($postfields as $k => $v) {
                $htmlOutput .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" />';
            }
            $htmlOutput .= '<input type="image" alt="Subscribe with onepay" src="https://storage.googleapis.com/onepayjs/subscribe-btn.png" title="Subscribe with onepay" width="154"/>';
            $htmlOutput .= '</form>';
        }
        
        // The 'One-time' Form
        if ($subscriptionsProcessedAs == 'option3' ||
            $defaultedToBothOptions){
                $backupfields['amount'] =  sprintf("%.2f",$amount);
                $backupfields['type'] = "ONETIME";
                $backupfields['duration'] = "1";
                // $requestData = array(
                //     "transaction_redirect_url" => $systemUrl. 'viewinvoice.php?id=' . $invoiceId,
                //     "customer_email" => $email,
                //     "customer_phone_number" => $phone,
                //     "reference" => (string)$invoiceId,
                //     "amount" => number_format(floatval($amount),2,'.',''),
                //     "app_id" =>  $appId,            
                //     'is_sdk' => "1",
                //     'sdk_type' => "WHMCS",
                //     'authorization' => $authKey,
                // );
                
                // $result_body = json_encode($requestData,JSON_UNESCAPED_SLASHES);
                // $result_body .= $secretKey;
                // echo ($result_body);
                // $hash_result = hash('sha256',$result_body);
    
                // $url .= "?hash=$hash_result";


            $htmlOutput .= '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
            foreach ($backupfields as $k => $v) {
                $htmlOutput .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" />';
            }
            $htmlOutput .= '<input type="image" alt="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" src="https://storage.googleapis.com/onepayjs/pay-btn.png" title="Pay with onepay" width="132"/>';
            $htmlOutput .= '</form>';
        }

        $htmlOutput .= '</td></tr></tbody></table>';
    }
    else{
        // Create a form with a single button (same as WHMCS plugin v1)


        $requestData = array(
            "transaction_redirect_url" => $systemUrl. 'viewinvoice.php?id=' . $invoiceId,
            "customer_email" => $email,
            "customer_phone_number" => $phone,
            "reference" => (string)$invoiceId,
            "amount" => number_format(floatval($amount),2,'.',''),
            "app_id" =>  $appId,            
            'is_sdk' => "1",
            'sdk_type' => "WHMCS",
            'authorization' => $authKey,
        );

        $result_body = json_encode($requestData,JSON_UNESCAPED_SLASHES);
        $result_body .= $secretKey;
        $hash_result = hash('sha256',$result_body);

        $url .= "?hash=$hash_result";

        
        $htmlOutput .= '<table style="border-collapse: separate; border-spacing: 15px 0;"><tbody><tr><td>';
        $htmlOutput .= '<form method="post" action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($postfields as $k => $v) {
            $htmlOutput .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '" />';
        }
        $htmlOutput .= '<input type="image" alt="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" src="https://storage.googleapis.com/onepayjs/pay-btn.png" title="Pay with onepay" width="132"/>';
        $htmlOutput .= '</form>';
        $htmlOutput .= '</td></tr></tbody></table>';
    }

    return $htmlOutput;

}