<?php
/**
 * WHMCS onepay Payment Gateway Module
 * Tax Retriever Helpers
 *
 * @see https://developer.onepay.lk
 * @copyright Copyright (c) 2021 onepay (Private) Limited
 * @license https://www.onepay.lk/legal
 * @version 1.0 RELEASE COPY
 */

use WHMCS\Database\Capsule;

/**
 * Gets the tax total for an invoice.
 * Takes in to account whether the TaxType
 * configuration in WHMCS is set to Exclusive.
 * @param string $invoiceId The invoice of which to calculate tax
 * @return integer
 */
function getTaxForInvoiceOnepay($invoiceId){
	$taxTypeSetting = Capsule::table('tblconfiguration')->where('setting', '=', 'TaxType')->first();
	$shouldAddTax = $taxTypeSetting->value === "Exclusive";

	if ($shouldAddTax){
		$invoice = Capsule::table('tblinvoices')->where('id', '=', $invoiceId)->first();
		$tax1 = $invoice->tax;
		$tax2 = $invoice->tax2;

		return $tax1 + $tax2;
	}
	else{
		return 0;
	}
}