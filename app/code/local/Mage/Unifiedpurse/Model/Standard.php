<?php

class Mage_Unifiedpurse_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code  = 'unifiedpurse_standard';
    protected $_formBlockType = 'unifiedpurse/standard_form';

    protected $_isGateway               = false;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = false;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;

    protected $_order = null;


    public function addTransaction($transaction)
    {
		$write = Mage::getSingleton('core/resource')->getConnection('core_write');
		
		$sql="CREATE TABLE IF NOT EXISTS mage_unifiedpurse(
		id INT NOT NULL AUTO_INCREMENT,
		PRIMARY KEY(id),
		order_id INT NOT NULL,INDEX(order_id),
		time INT NOT NULL,
		transaction_reference VARCHAR(10) NOT NULL,UNIQUE(transaction_reference),
		approved_amount DOUBLE NOT NULL,
		customer_email VARCHAR(160) NOT NULL,
		response_description VARCHAR(225) NOT NULL,
		response_code VARCHAR(5) NOT NULL,
		transaction_amount DOUBLE NOT NULL,
		customer_fullname VARCHAR(120) NOT NULL,
		currency_code VARCHAR(3) NOT NULL,
		status TINYINT(1) NOT NULL DEFAULT '0'
		)";
		$write->query($sql);
		
		$write->insert('mage_unifiedpurse',$transaction);
		return true;
    }
	
	
    /**
     * Get Config model
     *
     * @return object Mage_Unifiedpurse_Model_Config
     */
    public function getConfig()
    {
        return Mage::getSingleton('unifiedpurse/config');
    }

	
	
	
    /**
     * Return debug flag
     *
     *  @return  boolean
     */
    public function getDebug ()
    {
        return $this->getConfig()->getDebug();
    }

    /**
     *  Returns Target URL
     *
     *  @return	  string Target URL
     */
    public function getUnifiedpurseUrl ()
    {
        return 'https://unifiedpurse.com/sci';
    }

    /**
     *  Return URL for Unifiedpurse success response
     *
     *  @return	  string URL
     */
    protected function getSuccessURL ()
    {
        return Mage::getUrl('unifiedpurse/standard/successresponse');
    }

    /**
     *  Return URL for Unifiedpurse transaction history
     *
     *  @return	  string URL
     */
    protected function getHistoryURL ()
    {
       // return Mage::getUrl('unifiedpurse/standard/history');
        return Mage::getUrl('unifiedpurse/standard/successresponse');
    }

    /**
     * Transaction unique ID sent to Unifiedpurse and sent back by Unifiedpurse for order restore
     * Using created order ID
     *
     *  @return	  string Transaction unique number
     */
    protected function getVendorTxCode ()
    {
        return $this->getOrder()->getRealOrderId();
    }

    /**
     *  Returns cart formatted
     *  String format:
     *  Number of lines:Name1:Quantity1:CostNoTax1:Tax1:CostTax1:Total1:Name2:Quantity2:CostNoTax2...
     *
     *  @return	  string Formatted cart items
     */
    protected function getFormattedCart ()
    {
        $items = $this->getOrder()->getAllItems();
        $resultParts = array();
        $totalLines = 0;
        if ($items) {
            foreach($items as $item) {
                if ($item->getParentItem()) {
                    continue;
                }
                $quantity = $item->getQtyOrdered();

                $cost = sprintf('%.2f', $item->getBasePrice() - $item->getBaseDiscountAmount());
                $tax = sprintf('%.2f', $item->getBaseTaxAmount());
                $costPlusTax = sprintf('%.2f', $cost + $tax/$quantity);

                $totalCostPlusTax = sprintf('%.2f', $quantity * $cost + $tax);

                $resultParts[] = str_replace(':', ' ', $item->getName());
                $resultParts[] = $quantity;
                $resultParts[] = $cost;
                $resultParts[] = $tax;
                $resultParts[] = $costPlusTax;
                $resultParts[] = $totalCostPlusTax;
                $totalLines++; //counting actual formatted items
            }
       }

       // add delivery
       $shipping = $this->getOrder()->getBaseShippingAmount();
       if ((int)$shipping > 0) {
           $totalLines++;
           $resultParts = array_merge($resultParts, array('Shipping','','','','',sprintf('%.2f', $shipping)));
       }

       $result = $totalLines . ':' . implode(':', $resultParts);
       return $result;
    }

   

    /**
     *  Form block description
     *
     *  @return	 object
     */
    public function createFormBlock($name)
    {
        $block = $this->getLayout()->createBlock('unifiedpurse/form_standard', $name);
        $block->setMethod($this->_code);
        $block->setPayment($this->getPayment());
        return $block;
    }

    /**
     *  Return Order Place Redirect URL
     *
     *  @return	  string Order Redirect URL
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('unifiedpurse/standard/redirect');
    }

    /**
     *  Return encrypted string with simple XOR algorithm
     *
     *  @param    string String to be encrypted
     *  @return	  string Encrypted string
     */
    protected function simpleXOR ($string)
    {
        $result = '';
        $cryptKey = $this->getConfig()->getCryptKey();

        if (!$cryptKey) {
            return $string;
        }

        // Initialise key array
        $keyList = array();

        // Convert $cryptKey into array of ASCII values
        for($i = 0; $i < strlen($cryptKey); $i++){
            $keyList[$i] = ord(substr($cryptKey, $i, 1));
        }

        // Step through string a character at a time
        for($i = 0; $i < strlen($string); $i++) {
            /**
             * Get ASCII code from string, get ASCII code from key (loop through with MOD),
             * XOR the two, get the character from the result
             * % is MOD (modulus), ^ is XOR
             */
            $result .= chr(ord(substr($string, $i, 1)) ^ ($keyList[$i % strlen($cryptKey)]));
        }
        return $result;
    }

    /**
     *  Extract possible response values into array from query string
     *
     *  @param    string Query string i.e. var1=value1&var2=value3...
     *  @return	  array
     */
    protected function getToken($queryString) {

        // List the possible tokens
        $Tokens = array(
                        "Status",
                        "StatusDetail",
                        "VendorTxCode",
                        "VPSTxId",
                        "TxAuthNo",
                        "Amount",
                        "AVSCV2",
                        "AddressResult",
                        "PostCodeResult",
                        "CV2Result",
                        "GiftAid",
                        "3DSecureStatus",
                        "CAVV"
                        );

        // Initialise arrays
        $output = array();
        $resultArray = array();

        // Get the next token in the sequence
        $c = count($Tokens);
        for ($i = $c - 1; $i >= 0 ; $i--){
            // Find the position in the string
            $start = strpos($queryString, $Tokens[$i]);
            // If it's present
            if ($start !== false){
                // Record position and token name
                $resultArray[$i]['start'] = $start;
                $resultArray[$i]['token'] = $Tokens[$i];
            }
        }

        // Sort in order of position
        sort($resultArray);

        // Go through the result array, getting the token values
        $c = count($resultArray);
        for ($i = 0; $i < $c; $i++){
            // Get the start point of the value
            $valueStart = $resultArray[$i]['start'] + strlen($resultArray[$i]['token']) + 1;
            // Get the length of the value
            if ($i == $c-1) {
                $output[$resultArray[$i]['token']] = substr($queryString, $valueStart);
            } else {
                $valueLength = $resultArray[$i+1]['start'] - $resultArray[$i]['start'] - strlen($resultArray[$i]['token']) - 2;
                $output[$resultArray[$i]['token']] = substr($queryString, $valueStart, $valueLength);
            }

        }

        return $output;
    }

    /**
     *  Convert array (key => value, key => value, ...) to crypt string
     *
     *  @param    array Array to be converted
     *  @return	  string Crypt string
     */
    public function arrayToCrypt ($array)
    {
        $parts = array();
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                $parts[] = $k . '=' . $v;
            }
        }
        $result = implode('&', $parts);
        $result = $this->simpleXOR($result);
        $result = $this->base64Encode($result);
        return $result;
    }

    /**
     *  Reverse arrayToCrypt
     *
     *  @param    string Crypt string
     *  @return	  array
     */
    public function cryptToArray ($crypted)
    {
        $decoded = $this->base64Decode($crypted);
        $uncrypted = $this->simpleXOR($decoded);
        $tokens = $this->getToken($uncrypted);
        return $tokens;
    }

    /**
     *  Custom base64_encode()
     *
     *  @param    String
     *  @return	  String
     */
    protected function base64Encode($plain)
    {
        return base64_encode($plain);
    }

    /**
     *  Custom base64_decode()
     *
     *  @param    String
     *  @return	  String
     */
    protected function base64Decode($scrambled)
    {
        // Fix plus to space conversion issue
        $scrambled = str_replace(" ","+",$scrambled);
        return base64_decode($scrambled);
    }

    /**
     *  Return Standard Checkout Form Fields for request to Unifiedpurse
     *
     *  @return	  array Array of hidden form fields
     */
    public function getStandardCheckoutFormFields ()
    {
        $order = $this->getOrder();		
        $amount = $order->getBaseGrandTotal();
        $description = Mage::app()->getStore()->getName() . ' ' . ' payment';
        $order_id = $this->getVendorTxCode();		
		$time=time();
		$transaction_reference=$time;
		
		$notify_url=$this->getSuccessURL();
		$curr_code=566;
		$pay_item_id=101;
		$temp_amount=sprintf('%.2f', $amount);
		
		$tranx_amt=$temp_amount*100;
		$full_name=$order->getCustomerName();
		$email=$order->getCustomerEmail();
		$unifiedpurse_merchant_id=$this->getConfig()->getMerchantID();
		$currency_code=$order->getOrderCurrencyCode();
					
//        'Crypt'             => $this->getCrypted()		
        $fields = array(
                        'receiver'=> $unifiedpurse_merchant_id,
                        'amount'=> $temp_amount,
                        'currency'=> $currency_code,
                        'email'=> $email,
                        'ref'=> $transaction_reference,
                        'memo'=> $description,
                        'notification_url'=> $notify_url,												
                        'success_url'=> $notify_url,												
                        'cancel_url'=> $notify_url,												
                        //'cancel_url'=> $this->getHistoryURL(),										
                       );
					   
		$transaction=array(
			'transaction_reference'=>$transaction_reference,
			'order_id'=>$order_id,
			'time'=>$time,
			'customer_email'=>$email,
			'customer_fullname'=>$full_name,
			'transaction_amount'=>$temp_amount,
			'currency_code'=>$currency_code	
		);
		
		
		$trans_url=$notify_url;
		if(!stristr($trans_url,'?'))$trans_url.='?';
		$trans_url.="&txnRef=$transaction_reference";
		
		//$order->sendNewOrderEmail();
		$formatted_amount=number_format($temp_amount,2);
		$host=$_SERVER['HTTP_HOST'];
		if(strtolower(substr($host,0,4))=='www.')$host=substr($host,4);
		
		$mail_body="Dear $full_name\r\n\r\nBelow is your payment transaction information\r\n\r\nTransaction Reference: $transaction_reference\r\nOrder Id: $order_id\r\nAmount: $formatted_amount $currency_code\r\nDetails: $description\r\n\r\nPlease note that you can always check your transaction status here: $trans_url\r\n\r\nRegards.";
		mail($email,"Unifiedpurse Payment Transaction",$mail_body,"From: no-reply@$host");
		$this->addTransaction($transaction);
        return $fields;
    }
}