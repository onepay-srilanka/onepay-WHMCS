<?php
/**
 * WHMCS onepay Payment Gateway Module
 * Recurring Payment Helpers
 *
 * @see https://developer.onepay.lk
 * @copyright Copyright (c) 2021 onepay (Private) Limited
 * @license https://www.onepay.lk/legal
 * @version 1.0 RELEASE COPY
 */

use WHMCS\Database\Capsule;

/**
 * Returns the first recurring product/service in the
 * invoice. The invoiceId must be passed in to this function.
 * 
 * @param string $invoiceId The invoice item to retrieve the first hosting product from
 * @return onepayConsumableProduct
 */
function getFirstRecurringProductOnepay($invoiceId){
	$firstRecurringProduct = null;
	$invoiceItems = Capsule::table('tblinvoiceitems')->where([
									['invoiceid', '=', $invoiceId],
									['type', '=', 'Hosting']
								])->get();

	if (!empty($invoiceItems)){
		foreach ($invoiceItems as $item){
			$invoiceItemId = $item->id;
			$phProduct = getConsumableProductsForTblInvoiceItemIdOnepay($invoiceItemId);

			if ($phProduct->isRecurring){
				$firstRecurringProduct = $phProduct;
                break;
			}
			else{
				$firstRecurringProduct = null;
			}
		}
	}

	return $firstRecurringProduct;
}

/**
 * Returns the startup and recurrence total
 * for a given invoice
 * @param string $invoiceId The invoice to calculate the startup and recurrence
 * @param onepayConsumableProduct $firstProduct The first recurring product for the invoice
 * @return onepayPriceData
 */
function getStartupAndRecurrenceTotalForInvoiceWithFirstProductOnepay($invoiceId, $firstProduct){

	do_log_onepay('PH: getStartupAndRecurrenceTotalForInvoiceWithFirstProductOnepay invoked ');

	$startupFeeTotal = 0.0;
	$recurringTotal = 0.0;
	
	$invoiceItems = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invoiceId)->get();
	foreach ($invoiceItems as $item){
		$id = $item->id;
		$phProduct = getConsumableProductsForTblInvoiceItemIdOnepay($id);

		// Consider the product's properties
		// and act appropriately

		if(!$phProduct->isRecurringButErrornous && $phProduct->isRecurring && !$phProduct->isUnknownProductType){
			// Is genuinely recurring

			// Do the recurrence match?
			$periodsMatch = $firstProduct->recurringPeriod == $phProduct->recurringPeriod;
			$durationsMatch = $firstProduct->recurringDuration == $phProduct->recurringDuration;

			if ($periodsMatch && $durationsMatch){
				$startupFeeTotal += $phProduct->recurringStartupFee;
				$recurringTotal += $phProduct->unitPrice;
				do_log_onepay('PH: Adding invoice item ' . $id . ' to cart as a recurring product ST = ' . $phProduct->recurringStartupFee . ' REC = '. $phProduct->unitPrice);
			}
			else{
				do_log_onepay('PH: Skipping invoice item ' . $id);
			}
		}
		else if(!$phProduct->isUnknownProductType && !$phProduct->isRecurring){
			// Is genuinely non-recurring (consder as a startup product)
			$startupFeeTotal += $phProduct->unitPrice;
			do_log_onepay('PH: Adding invoice item ' . $id . ' to cart as a normal product AMT = ' . $phProduct->unitPrice);
		}
		else{
			do_log_onepay('PH: Unknown error with invoice item ' . $id . '');
		}
		do_log_onepay('PH: loop status ST = ' . $startupFeeTotal . ' REC = ' . $recurringTotal . ' ');
	}

	return new onepayPriceData($startupFeeTotal, $recurringTotal);
}