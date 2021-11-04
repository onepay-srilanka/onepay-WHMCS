<?php
/**
 * WHMCS onepay Payment Gateway Module
 * Plugin Class Definitions
 *
 * @see https://developer.onepay.lk
 * @copyright Copyright (c) 2021 onepay (Private) Limited
 * @license https://www.onepay.lk/legal
 * @version 1.0 RELEASE COPY
 */

define('DO_DEBUG', '');

/**
 * If the passed object is null, and the DO_DEBUG
 * flag is not set to true, throws an exception initialized
 * using the message. Otherwise, prints a log message.
 *
 * @param mixed $object The object to test whether it is null
 * @param string $messageToLogIfNull Message to print if the object is null
 * @return void
 */
function do_log_onepay_or_exception_if_null_onepay($object, $messageToLogIfNull){
    $msg = $messageToLogIfNull;
    if (strlen($msg) == 0){
        throw new Exception("onepay: do_log_onepay method: receieved a message with 0 string length = \"" . $message . "\"");
    }
    else{
        if ($object == null){
            if (DO_DEBUG === "true"){
                do_log_onepay($messageToLogIfNull);
            }
            else{
                throw new Exception($msg);
            }
        }
    }
}

/**
 * Prints out a log message if the DO_DEBUG
 * flag is set to true
 *
 * @param string $message The string message to print
 * @return void
 */
function do_log_onepay($message){
    $msg = $message;
    if (strlen($msg) == 0){
        throw new Exception("Onepay: do_log_onepay method: receieved a message with 0 string length = \"" . $message . "\"");
    }
    else{
        $endsWithSpace = $msg[max(0, strlen($msg)) - 1] == ' ';
        if (DO_DEBUG === "true"){
            if (!$endsWithSpace){
                $msg = $msg . ' ';
            }
            
            echo "<div><p style='padding: 2px 4px 2px 3px; margin: 3px 3px 1px 3px; display: inline-block; background-color: #9bffd9; border: 1px solid #00a063; border-radius: 5px;'>" . $msg . '</p></div>';
        }
    }
}

/**
 * A type to store recurring words in a 
 * unifieid manner. 
 * For example, the value '2 Week'
 * can be stored in an ordered manner
 * using this type.
 */
class onepaySplitWordResponse{

    public $number = 0;
    public $word = "Month";

    public function initFromWord($param){
        $splitted = explode(' ', $param);
        if (count($splitted) == 2){
            $this->number = $splitted[0];
            $this->word = $splitted[1];
        }
    }

    public function initFromMonths($months){
        if ($months > 12){
            if ($months % 12 == 0){
                $this->number = $months / 12;
                $this->word = "Year";
            }
            else{
                throw new Exception("onepaySplitWordResponse.initFromMethods() - Unknown state");
            }
        }
        else{
            $this->number = $months;
            $this->word = "Year";
        }
    }

    public function toString(){
        return $this->number . ' ' . $this->word;
    }

    public function inMonths(){
        if ($this->word == "Year" || $this->word == "year"){
            return $this->number * 12;
        }
        return $this->number;
    }

}

/**
 * A type to encapsulate recurring details
 * about a product in a unified manner
 */
class onepayConsumableProduct{

    /**
     * The invoice item it for this product
     * @var string
     */
    public $invoiceItemId = "";

    /**
     * Is this product recurring?
     * @var boolean
     */
    public $isRecurring = false;

    /**
     * Is this product recurring forever?
     * @var boolean
     */
    public $isRecurringForever = false;

    /**
     * The recurring period. In onepay terms,
     * the ```recurrence```.
     * E.g: 2 Week
     * @var string
     */
    public $recurringPeriod = "";

    /**
     * The recurring duration. In onepay terms,
     * the ```duration```.
     * E.g: 2 Week
     * @var string
     */
    public $recurringDuration = "";

    /**
     * The start-up fee for this product. 
     * The default value is 0.0, if the product
     * does not have a start-up fee or is not
     * recurring
     * @var float
     */
    public $recurringStartupFee = 0.0;
    
    /**
     * The amount of the product. If the product
     * is not recurring then it is the equivalent 
     * of ```tblinvoiceitems->amount```. 
     * If the product is recurring, this value might
     * or might not be equivalent to the amount 
     * supplied by the ```tblinvoiceitems``` table.
     * @var float
     */
    public $unitPrice = 0.0;

    /**
     * If this value is true, it signifies that an 
     * internal parameter for this product caused
     * it to be incompatible with the onepay supported
     * recurring constraints.
     * 
     * Such products should not be taken into account
     * for startup fees or recurrence amounts.
     *
     * @var boolean
     */
    public $isRecurringButErrornous = false;

    /**
     * Indicates that this product could not be
     * used to construct the ```onepayConsumableProduct```
     * correctly, since the detail extractor cannot
     * identifier it's product type
     *
     * @var boolean
     */
    public $isUnknownProductType = false;

    //public $tableName = "";
    //public $relativeId = 0;

    /**
     * The total price of the product. If the 
     * product is recurring, this value is the total
     * of the start-up fee and the unit price.
     * Otherwise, it is equivalent to the 
     * unitprice.
     * 
     * Use this method when retrieving the
     * product's price, It is adivsed to not
     * use the raw `recurringStartupFee` or
     * `unitPrice`.
     *
     * @return double
     */
    function getPrice(){
        if($isRecurring){
            return $unitPrice + $recurringStartupFee;
        }
        else{
            return $unitPrice;
        }
    }
}

/**
 * A type to hold the final startup and 
 * recurring total.
 */
class onepayPriceData{
    /**
     * The startup fee total for the invoice
     * @var float
     */
    public $startupTotal = 0.0;

    /**
     * The recurring total for the invoice
     * @var float
     */
    public $recurringTotal = 0.0;

    /**
     * Constructs a new onepayPriceData object
     * @param float $starTot The startup total
     * @param float $recTot The recurrence total
     */
    function __construct($starTot, $recTot){
        $this->startupTotal = $starTot;
        $this->recurringTotal = $recTot;
    }
}