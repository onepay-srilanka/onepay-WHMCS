<?php
/**
 * WHMCS onepay Payment Gateway Module
 * onepay Consumable Product Helpers
 *
 * @see https://developer.onepay.lk
 * @copyright Copyright (c) 2021 onepay (Private) Limited
 * @license https://www.onepay.lk/legal
 * @version 1.0 RELEASE COPY
 */

use WHMCS\Database\Capsule;

/**
 * Returns the Recurring Period and Recurring Duration
 * based on the billing cycle and recurring cycle count.
 * @param string $billingcycle Expects the billing cycle to be in lower-case
 * @param integer $recurringcycle Expects the recurringcycle cycle to be an integer
 */
function getRecurringInfoForBillingCycle_onepay($billingcycle, $recurringcycle){

    $recurring_info = array(
        'period' => '',
        'duration' => '',
        'cycle' => 0,
        'recurring_word' => '',
        'recurring_modifier' => 1,
        'recurring_forever' => false
    );

    if ($billingcycle == "one time" || $billingcycle == "onetime"){
        // Do nothing
    }
    else if($billingcycle == "monthly"){
        $recurring_info['period'] = 'MONTHLY';
        $recurring_info['cycle'] = $recurringcycle;
        $recurring_info['duration'] = "1";
        $recurring_info['recurring_word'] = 'Month';
    }
    else if($billingcycle == "quarterly"){
        $recurring_info['period'] = 'QUARTERLY';
        $recurring_info['cycle'] = $recurringcycle;
        $recurring_info['duration'] = "1";
        $recurring_info['recurring_word'] = 'Month';
        $recurring_info['recurring_modifier'] = 3;
    }
    else if($billingcycle == "semi-annually"){
        $recurring_info['period'] = 'SEMI-ANNUALLY';
        $recurring_info['cycle'] = $recurringcycle;
        $recurring_info['duration'] = "1";
        $recurring_info['recurring_word'] = 'Month';
        $recurring_info['recurring_modifier'] = 6;
    }
    else if($billingcycle == "annually"){
        $recurring_info['period'] = 'ANNUALLY';
        $recurring_info['cycle'] = $recurringcycle;
        $recurring_info['duration'] = "1";
        $recurring_info['recurring_word'] = 'Year';
    }
    else if($billingcycle == "biennially"){
        $recurring_info['period'] = 'BI-ANNUALLY';
        $recurring_info['cycle'] = $recurringcycle;
        $recurring_info['duration'] = "1";
        $recurring_info['recurring_word'] = 'Year';
        $recurring_info['recurring_modifier'] = 2;
    }
    else if($billingcycle == "triennially"){
        $recurring_info['period'] = 'TRI-ANNUALLY';
        $recurring_info['cycle'] = $recurringcycle;
        $recurring_info['duration'] = "1";
        $recurring_info['recurring_word'] = 'Year';
        $recurring_info['recurring_modifier'] = 3;
    }
    else{
        do_log_onepay('OP: Unknown billing cycle passed found: "' . $billingcycle . "\"");
    }

    if ($recurringcycle == 0){
        $recurring_info['cycle'] = 0;
        $recurring_info['duration'] = "0";
        $recurring_info['recurring_forever'] = true;
    }

    return $recurring_info;
}

// *********************************
// MARK End: Helper methods
// *********************************

/**
 * Returns a ```onepayConsumableProduct``` from the
 * passed in ```invoiceItemId```
 *
 * @param string $invoiceItemId The invoice item id to retrieve
 * @return onepayConsumableProduct A onepay consumable product
 */
function getConsumableProductsForTblInvoiceItemIdOnepay($invoiceItemId){

    $_p_ = Capsule::table('tblinvoiceitems')->where('id', '=', $invoiceItemId)->first();

    // Stores the consumable product
    $consumable_product = new onepayConsumableProduct();

    // Basic values needed to select the 
    // information relevant to this product
    $relative_table_id_column_value = $_p_->relid;
    $product_type = strtolower($_p_->type);

    // Initialize consumable product with information
    // common to all types of invoice items
    $consumable_product->unitPrice = (double)$_p_->amount;
    $consumable_product->invoiceItemId = $_p_->id;

    if ($product_type == "setup"){
        // Nothing to do here
    }
    else if ($product_type == "hosting"){

        $hosting_details = Capsule::table('tblhosting')->where('id', '=', $relative_table_id_column_value)->first();
        do_log_onepay_or_exception_if_null_onepay($hosting_details, "OP: Hosting details could not be found");
        $billing_cycle = strtolower($hosting_details->billingcycle);

        if($billing_cycle != "one time"){

            $package_id = $hosting_details->packageid;
            $product_details = Capsule::table('tblproducts')->where('id', '=', $package_id)->first();
            $recurring_cycles = $product_details->recurringcycles;

            $recurring_info = getRecurringInfoForBillingCycle_onepay($billing_cycle, $recurring_cycles);

            $consumable_product->isRecurring = true;
            $consumable_product->isRecurringForever = $recurring_info['recurring_forever'];
            $consumable_product->recurringPeriod = $recurring_info['period'];
            $consumable_product->recurringDuration = $recurring_info['duration'];
            $consumable_product->cycle = $recurring_info['cycle'];
        }
    }
    else if ($product_type == "domainregister" || $product_type == "domaintransfer" || $product_type == "domainrenew"){
        $domain_details = Capsule::table('tbldomains')->where('id', '=', $relative_table_id_column_value)->first();
        do_log_onepay_or_exception_if_null_onepay($domain_details, "OP: Domain details could not be found");
        $registration_period = (int) $domain_details->registrationperiod;

        $existing_unit_price = $consumable_product->unitPrice;
        $first_payment_amount = $domain_details->firstpaymentamount;
        $recurring_amount = $domain_details->recurringamount;
        $billing_cycle_text = "";

        if ($registration_period == 1){
            $billing_cycle_text = "annually";
        }
        else if ($registration_period == 2){
            $billing_cycle_text = "biennially";
        }
        else if ($registration_period == 3){
            $billing_cycle_text = "triennially";
        }
        else{
            $consumable_product->isRecurringButErrornous = true;
        }

        if ($billing_cycle_text != ""){
            $recurring_info = getRecurringInfoForBillingCycle_onepay($billing_cycle_text, 0);

            $consumable_product->isRecurring = true;
            $consumable_product->isRecurringForever = true;
            $consumable_product->recurringPeriod = $recurring_info['period'];
            $consumable_product->recurringDuration = $recurring_info['duration'];
            $consumable_product->cycle = $recurring_info['cycle'];

            if ($existing_unit_price != $first_payment_amount){
                // A price override has been applied via product bundles.
                $consumable_product->recurringStartupFee = $existing_unit_price - $recurring_amount;
                $consumable_product->unitPrice = $recurring_amount;
            }
            else{
                // There are no price overrides via product bundles.
                $consumable_product->recurringStartupFee = $first_payment_amount - $recurring_amount;
                $consumable_product->unitPrice = $recurring_amount;
            }
        }

        // NOTE: Grace and Redemption Grace periods
        //
        // These occur conditionally when the customer fails
        // to correctly renew the domain. And hence these fees
        // are not to be handled by the payment gateway at this
        // point.
    }
    else if ($product_type == "addon"){
        $addon_details = Capsule::table('tblhostingaddons')->where('id', '=', $relative_table_id_column_value)->first();
        do_log_onepay_or_exception_if_null_onepay($addon_details, "OP: Addon details could not be found");
        $addon_billing_cycle = strtolower($addon_details->billingcycle);

        if ($addon_billing_cycle != "one time"){
            /*$addon_hosting_id = $addon_details->hostingid;
            $hosting_item = Capsule::table('tblhosting')->where('id', '=', $addon_hosting_id)->first();
            $hosting_item_product = $product_details = Capsule::table('tblproducts')->where('id', '=', $hosting_item->package_id)->first();
            $addon_recurring_cycles = $hosting_item_product->recurringcycles;*/

            $recurring_info = getRecurringInfoForBillingCycle_onepay($addon_billing_cycle, 0);
            
            $consumable_product->isRecurring = true;
            $consumable_product->isRecurringForever = $recurring_info['recurring_forever'];
            $consumable_product->recurringPeriod = $recurring_info['period'];
            $consumable_product->recurringDuration = $recurring_info['duration'];
            $consumable_product->cycle = $recurring_info['cycle'];
            $consumable_product->recurringStartupFee = $addon_details->setupfee;
            $consumable_product->unitPrice = $addon_details->recurring;
        }
    }
    else if ($product_type == "item"){ // Billable items
        
        $billable_item_details = Capsule::table('tblbillableitems')->where('id', '=', $relative_table_id_column_value)->first();
        do_log_onepay_or_exception_if_null_onepay($billable_item_details, "OP: Billable item details could not be found");
        $item_is_recurring = $billable_item_details->invoiceaction == 4;

        if ($item_is_recurring){
            // Recur every $billing_cycle_number $billing_cycle_ordinal for $billing_duration times
            // Ex:   every 3                     Weeks                  for 5                 times

            /** 
             * The magnitude of the billing cycle. E.g: 2.
             * @var int
             */
            $billing_cycle_number = $billable_item_details->recur;
            /**
             * The type of the billing cycle E.g: Weeks.
             * Possible values include 0, days, weeks, months, years
             * @var string
             */
            $billing_cycle_ordinal = strtolower($billable_item_details->recurcycle);
            /**
             * The duration of the recurrence. E.g: 2.
             * @var int
             */
            $billing_duration = $billable_item_details->recurfor;

            if ($billing_cycle_ordinal != 'weeks' && $billing_cycle_ordinal != 'days'){
                $consumable_product->isRecurring = true;
                $billing_cycle_proper_name = "";

                if ($billing_cycle_ordinal == 'years'){
                    if ($billing_cycle_number == 1){
                        $billing_cycle_proper_name = "annually";
                    }
                    else if ($billing_cycle_number == 2){
                        $billing_cycle_proper_name = "biennially";
                    }
                    else if ($billing_cycle_number == 3){
                        $billing_cycle_proper_name = "triennially";
                    }
                }
                else if ($billing_cycle_ordinal == 'months'){
                    if ($billing_cycle_number == 1){
                        $billing_cycle_proper_name = "monthly";
                    }
                    else if ($billing_cycle_number == 3){
                        $billing_cycle_proper_name = "quarterly";
                    }
                    else if ($billing_cycle_number == 6){
                        $billing_cycle_proper_name = "semi-annually";
                    }
                }

                if ($billing_cycle_proper_name != ""){
                    $recurring_info = getRecurringInfoForBillingCycle_onepay($billing_cycle_proper_name, $billing_duration);
                    $consumable_product->isRecurring = true;
                    $consumable_product->isRecurringForever = false;
                    $consumable_product->recurringPeriod = $recurring_info['period'];
                    $consumable_product->recurringDuration = $recurring_info['duration'];
                    $consumable_product->cycle = $recurring_info['cycle'];
                }
                else{
                    $consumable_product->isRecurringButErrornous = true;
                }
            }
        }
    }
    /*
    else if ($product_type == "latefee")
    else if ($product_type == "upgrade")
    */
    else if ($product_type == "invoice"){
        $inner_invoice_id = $relative_table_id_column_value;
        $firstRecProduct = getFirstRecurringProductOnepay($inner_invoice_id);
        $invoiceHasRecProducts = $firstRecProduct != null;

        if ($invoiceHasRecProducts){
            /**
             * The startup and recurrence total for the given invoiceid
             * @var onepayPriceData
             */
            $invRes = getStartupAndRecurrenceTotalForInvoiceWithFirstProductOnepay(
                $$inner_invoice_id, 
                $firstRecProduct
            );
            
            do_log_onepay_or_exception_if_null_onepay($invRes, "OP: Invoice result was null");

            $consumable_product->isRecurring = true;
            $consumable_product->isRecurringForever = $firstRecProduct->isRecurringForever;
            $consumable_product->recurringPeriod = $firstRecProduct->recurringPeriod;
            $consumable_product->recurringDuration = $firstRecProduct->recurringDuration;
            $consumable_product->recurringStartupFee = $invRes->startupTotal;
            $consumable_product->unitPrice = $invRes->recurringTotal;
        }
        
        $taxAmt = getTaxForInvoice($inner_invoice_id);
        $consumable_product->unitPrice = $consumable_product->unitPrice + $taxAmt;
        if ($invoiceHasRecProducts){
            $consumable_product->recurringStartupFee = $consumable_product->recurringStartupFee + $taxAmt;
        }
    }
    else if ($product_type == "promohosting"){
        // The Product the promotion is associated with
        $hosting_details = Capsule::table('tblhosting')->where('id', '=', $relative_table_id_column_value)->first();
        do_log_onepay_or_exception_if_null_onepay($hosting_details, "OP: Hosting details could not be found while looking up Promotion");

        // The actual promotion id
        $promo_id = $hosting_details->promoid;
        
        // Promotion details
        $promotion_detail = Capsule::table('tblpromotions')->where('id', '=', $promo_id)->first();

        /**
         * The number of cycles to repeat the subscription
         * @var int
         */
        $promo_recurfor = $promotion_detail->recurfor;

        /**
         * Whether this is a recurring promotion
         */
        $promo_is_recurring = $promotion_detail->recurring == 1;

        //$consumable_product->productNumber = $promo_description;

        if ($promo_is_recurring){
            
            $hosting_billing_cycle = strtolower($hosting_details->billingcycle);

            if ($hosting_billing_cycle != "one time"){

                $hosting_product_package_id = $hosting_details->packageid;
                $hosting_details = Capsule::table('tblproducts')->where('id', '=', $hosting_product_package_id)->first();
                $product_recurring_cycles = $hosting_details->recurringcycles;
                $recurring_info = getRecurringInfoForBillingCycle_onepay($hosting_billing_cycle, $product_recurring_cycles);

                $consumable_product->isRecurring = true;
                $consumable_product->isRecurringForever = (bool) $promo_recurfor == 0;
                $consumable_product->recurringPeriod = $recurring_info['period'];
                $consumable_product->recurringDuration = $recurring_info['duration'];
                $consumable_product->cycle = $recurring_info['cycle'];
            }
        }
    }
    else if ($product_type == "promodomain"){
        $domain_details = Capsule::table('tbldomains')->where('id', '=', $relative_table_id_column_value)->first();
        do_log_onepay_or_exception_if_null_onepay($domain_details, "OP: Domain details could not be found whiling looking up Promotion");
        $promo_id = $domain_details->promoid;
        
        $promotion_detail = Capsule::table('tblpromotions')->where('id', '=', $promo_id)->first();
        $promo_recurfor = $promotion_detail->recurfor; // 0 means unlimited
        $promo_is_recurring = $promotion_detail->recurring;

        $product_registration_period = $domain_details->registrationperiod;

        if ($promo_is_recurring){
            if ($product_registration_period < 4){
                $consumable_product->isRecurring = true;
                $consumable_product->recurringPeriod = "ANNUALLY";
                $consumable_product->recurringDuration = $product_registration_period . " Year";
            }
            else{
                $consumable_product->isRecurring = true;
                $consumable_product->isRecurringButErrornous = true;
            }
        }
    }
    else if ($product_type == "" && $relative_table_id_column_value == 0){ // Quote item
        // Nothing to do here.
        // This is just to prevent the 
        // `isUnknownProductType` flag from being set
        // on the consumable product in a less illogical way.
    }
    else{
        // Handle unknown product type
        $consumable_product->isUnknownProductType = true;
    }

    return $consumable_product;
}