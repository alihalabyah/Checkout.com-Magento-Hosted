<?php
class Checkout_HostedPayment_Model_Api {

   public function __construct(){
        $options = array('trace'=>true);
    }

    public function beginPayment($payment, $desc, $uriSuccess, $uriCancel, $merchantcode, $password, $action, $isRecurring, $isTest, $tokenServiceURL){

    	$order = $payment->getOrder();
    	$billing = $payment->getOrder()->getBillingAddress();
    	$shipping = $order->getShippingAddress();

        $_fromCurr = 'USD';
        $_toCurr = 'AED';

        $amount = Mage::helper('directory')->currencyConvert( $payment->getAmount(), $_fromCurr, $_toCurr );

    	//PHP  version 5.4
    	//Build Array Payment token request
    	$request_array = array(
    			// Mandatory fields
    			'paymentmode' => $isRecurring, //paymentmode = 0 for normal payment, paymentmode = 1 for recurring type
    			// 'amount' => number_format($payment->getAmount(), 2, '.', ''),
                'amount' => number_format($amount, 2, '.', ''),
    			// 'currencysymbol' => $order->getBaseCurrencyCode(),
                'currencysymbol' => 'AED',
    			'merchantcode' => $merchantcode,
    			'password' => $password,
    			'action' => $action,
    			'trackid' => $order->getIncrementId(),
    			'returnurl' => $uriSuccess,
    			'cancelurl' => $uriCancel//,
    			/*
    			// Mandatory for paymentmode = 1, Recurring
    			  'recurring_flag' => '1',
    			  'recurring_interval' => '1',
    			  'recurring_intervaltype' => 'Day',
    			  'recurring_startdate' => '08/24/2013',
    			  'recurring_transactiontype' => '1',
    			  'recurring_amount' => '1.1',
    			  'recurring_auto' => '1',
    			  'recurring_number' => '20',
    			// Trial features optional for paymentmode = 1, Recurring
    			  'trial_transactiontype' => '1',
    			  'trial_startdate' => '08/23/2013',
    			  'trial_amount' => '2.00',
    			  'trial_number' => '2',
    			  'trial_intervaltype' => 'DAY'
    			*/
    	);

    	$ch = curl_init();
    	curl_setopt($ch, CURLOPT_URL, $tokenServiceURL);
    	curl_setopt($ch, CURLOPT_POST, 1);
    	curl_setopt($ch, CURLOPT_HTTPHEADER, Array("Content-Type: application/json; charset=utf-8"));
    	curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($request_array));
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    	$server_output = curl_exec ($ch);
    	curl_close ($ch);

    	//read JSon response
    	$response = json_decode($server_output,TRUE);
    	$Payment_Token = "";

    	foreach( (array) $response as $key => $value ) {
    		if($key == "PaymentToken"){
    			$Payment_Token = $value;
    		}
    	}

        return $Payment_Token;
    }

    public function isValidPayment($VerifyKey, $Signature){
    	//Transaction response handler

       //create signature
       //append values by sorting  the keys in ascending order excluding sig.
       //e.g. authcode,error_code_tag,error_text,merchantid,responsecode,result,trackid,tranid
       $arrKeys = $_GET;
       uksort($arrKeys, 'strcasecmp');

       $responseValues = "";

       foreach ($arrKeys as $key => $val) {
	       	if($key!='sig')
	       	{
	       		//echo $key.'<br/>';
	       	 $responseValues .= $_GET[$key];
	       	}
        }


       $HashResponse = hash("sha512",$responseValues.strtoupper($VerifyKey));

       if( strtoupper($Signature) == strtoupper($HashResponse)){
           return true;
       }else{
           return false;
       }
    }
}