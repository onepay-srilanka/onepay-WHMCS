<?php
/**
 * WHMCS onepay Payment Gateway Module
 *
 * @see https://onepay.lk
 * @copyright Copyright (c) 2021 onepay (Private) Limited
 * @license https://privacy-onepay.spemai.com/
 * @version 1.0 RELEASE COPY
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
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
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
            'Description' => 'App token you set in your oenpay Account',
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
    $appId = $params['appID'];
    $secretKey = $params['secretKey'];
    $authKey= $params['authKey'];
    $subscriptionsProcessedAs = $params['subscriptionsProcessedAs'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params['description'];
    //$amount = $params['amount']? number_format($params['amount'], 2, '.', '') : "0.00";
    $amount=sprintf("%.2f",floatval($params['amount']));
    $currencyCode = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    //$url = 'https://merchant-api-development.onepay.lk/api/ipg/gateway/whmc/';
    //$url = 'http://localhost:8001/api/ipg/gateway/whmc/';
    $url = 'https://merchant-api-live-v2.onepay.lk/api/ipg/gateway/whmc/';


    // Fields for the form
    $postfields = array();
    
    // Backup of the original form
    // since we need one for the "Pay Now"
    // button when Recurring payments
    // are available
    $backupfields = array();

    // DB Access Helpers
    if (file_exists('../../dbconnect.php')) {
		include '../../dbconnect.php';
	} else if (file_exists('../../init.php')) {
		include '../../init.php';
    } else{ 
        //logToTable('Its an error!');
    }

    $postfields['app_id'] = $appId;
    $postfields['transaction_callback_url'] = $systemUrl . 'modules/gateways/callback/' . $moduleName . '.php';
    $postfields['transaction_redirect_url'] =  $systemUrl. 'viewinvoice.php?id=' . $invoiceId;
    $postfields['customer_first_name'] = $firstname;
    $postfields['customer_last_name'] = $lastname;
    $postfields['customer_email'] = $email;
    $postfields['customer_phone_number'] = $phone;
    $postfields['reference'] = $invoiceId;
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
        $postfields['recurring_amount'] = $result->recurringTotal;
        $postfields['amount'] = number_format(floatval($result->startupTotal + $result->recurringTotal),2,'.','');
        $postfields['rec_type'] = $firstRecurringProduct->recurringPeriod;
        // $postfields['duration'] = $firstRecurringProduct->recurringDuration;

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
        do_log_onepay('PH: Not using recurring payments');
        // $postfields['item_name_1'] = $description;
        // $postfields['amount_1'] = $amount;
        // $postfields['quantity_1'] = "1"; 
        // $postfields['item_number_1'] = $invoiceId;
        $postfields['amount'] =  sprintf("%.2f",$amount);
        $postfields['type'] = "ONETIME";
    }
    
    // Add tax 
    $taxAmount = getTaxForInvoiceOnepay($invoiceId);
    
    if ($invoiceContainsRecurringItems && $canCallRecurringApiIfNeeded){
        // DEBUG tax amount
        do_log_onepay('PH: Adding a tax of ' . $taxAmount);
        // $postfields['amount_1'] = $postfields['amount_1'] + $taxAmount;number_format(floatval($taxAmount),2,'.','');
        // $postfields['amount'] = $postfields['amount'] +  number_format(floatval($taxAmount),2,'.','');
        do_log_onepay('PH: Final ST = ' . $postfields['startup_fee'] . ' REC = ' . $postfields['amount'] . ' Recurs every ' . $postfields['recurrence'] . ' for ' . $postfields['duration']);
    }
    else{
        do_log_onepay('PH: Tax is included in the invoice. Tax = ' . $taxAmount);
        do_log_onepay('PH: Final AMT = ' . $postfields['amount']);
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
                
            $htmlOutput .= '<form method="post" action="' . $url . '" style="margin-bottom: 0;">';
            foreach ($postfields as $k => $v) {
                $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />';
            }
            $htmlOutput .= '<input type="image" alt="' . 'Subscribe with onepay' . '" src="https://onepayserviceimages.s3.ap-southeast-1.amazonaws.com/subscribebtn.png" title="Subscribe with onepay" width="154"/>';
            $htmlOutput .= '</form>';
        }
        
        // The 'One-time' Form
        if ($subscriptionsProcessedAs == 'option3' ||
            $defaultedToBothOptions){


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


            $htmlOutput .= '<form method="post" action="' . $url . '">';
            foreach ($backupfields as $k => $v) {
                $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />';
            }
            $htmlOutput .= '<input type="image" alt="' . $langPayNow . '" src="https://onepayserviceimages.s3.ap-southeast-1.amazonaws.com/paybtn.png" title="Pay with onepay" width="132"/>';
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
        $htmlOutput .= '<form method="post" action="' . $url . '">';
        foreach ($postfields as $k => $v) {
            $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . $v . '" />';
        }
        $htmlOutput .= '<input type="image" alt="' . $langPayNow . '" src="https://onepayserviceimages.s3.ap-southeast-1.amazonaws.com/paybtn.png" title="Pay with onepay" width="132"/>';
        $htmlOutput .= '</form>';
        $htmlOutput .= '</td></tr></tbody></table>';
    }

    return $htmlOutput;

}