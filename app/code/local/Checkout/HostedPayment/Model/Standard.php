<?php
class Checkout_HostedPayment_Model_Standard extends Mage_Payment_Model_Method_Abstract
{

    //payment method code. Used in XML config and Db.
	protected $_code  = 'HostedPayment';
    protected $_isGateway               = true;

    // Payment Actions
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;

    //flag which causes initalize() to run when checkout is completed.
	protected $_isInitializeNeeded      = false;

    //Disable payment method in admin/order pages.
    protected $_canUseInternal          = false;

    /*
	    If you want to use a method only for admin override their values in your payment model as follows:

	    protected $_canUseInternal              = true; //can use in admin
		protected $_canUseCheckout              = true; //can use in onepage checkout
    */

    //Disable multi-shipping for this payment module.
    protected $_canUseForMultishipping  = false;

    /**
    * Return URL to redirect the customer to.
    * Called after 'place order' button is clicked.
    * Called after order is created and saved.
    * @return string
    */
    public function getOrderPlaceRedirectUrl()
    {
        /* Mage log is your friend.
         * While it shouldnt be on in production,
         * it makes debugging problems with your api much easier.
         * The file is in magento-root/var/log/system.log
         */
        mage::log('Called custom ' . __METHOD__);

        $hash = "";
        $VerifyKey = "";
        $Payment_Token = "";
        $ProductDescription = "";

        $url = $this->getConfigData('redirecturl');

        $session = Mage::getSingleton('checkout/session');
        $VerifyKey = Mage::helper('core')->decrypt($session->getData('VerifyKey'));

        $Payment_Token = $session->getData('PaymentToken');
        $ProductDescription = $session->getData('ProductDescription');

        if($Payment_Token <> ""){
        	$hash = hash("sha512", $Payment_Token.strtoupper($VerifyKey));
        }

        $url = $url."?pt=".urlencode($Payment_Token)."&sig=".urlencode($hash)."&ProductDesc=".urlencode($ProductDescription);

		$order=Mage::getModel('sales/order')->loadByIncrementId($session->getData('trackid'));
		$msg = "Waiting for capture response.";
		$order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT ,true,$msg,false);
        $order->setIsNotified(false);
		$order->save();

        return $url;
    }

    /**
     * this method is called if we are just authorising
     * a transaction
     */
    public function authorize (Varien_Object $payment, $amount)
    {
		$payment->setAmount($amount);
    	$this->_customBeginPayment($payment, '4');
    	return $this;
    }

    /**
     * this method is called if we are authorising AND
     * capturing a transaction
     */
    public function capture (Varien_Object $payment, $amount)
    {
		$payment->setAmount($amount);
    	$this->_customBeginPayment($payment, '1');
    	return $this;
    }

    /**
     * called if refunding
     */
    public function refund (Varien_Object $payment, $amount)
    {
    	Mage::throwException("Refund not Supported.");
    	return $this;
    }

    /**
     * called if voiding a payment
     */
    public function void (Varien_Object $payment)
    {
		Mage::throwException("Voi not Supported.");
		return $this;
    }

    /**
     *
     * Extract cart/quote details and send to Json call
     */
    protected  function _customBeginPayment(Varien_Object $payment, $action){
        //Retrieve the wsdl and endpoint from the magento global config table.
        $api = new Checkout_HostedPayment_Model_Api();

        //Retrieve cart/quote information.
        $sessionCheckout = Mage::getSingleton('checkout/session');
        $quoteId = $sessionCheckout->getQuoteId();

        //The quoteId will be removed from the session once the order is placed.
        //If you need it, save it to the session yourself.
        $sessionCheckout->setData('CheckoutQuoteId',$quoteId);

        $quote = Mage::getModel("sales/quote")->load($quoteId);

        $grandTotal = $quote->getData('grand_total');
        $subTotal = $quote->getSubtotal();
        $shippingHandling = ($grandTotal-$subTotal);
		$order = $payment->getOrder();

		$apiDesc = '';

		$_order = Mage::getModel('sales/order')->load($order->getId());
		$items = $_order->getAllItems();

		foreach ($items as $itemId => $item)
		{
			if ($apiDesc != '')
			{
				$apiDesc .= ',';
			}

			$apiDesc .= '{"itemnumber":"'.$item->getProductId().'","name":"'.$item->getName().'","unitprice":"'.number_format($item->getPrice(), 2, '.', '').'", "quantity":"'.$item->getQtyToInvoice().'","amount":"'.number_format($item->getPrice()*$item->getQtyToInvoice(), 2, '.', '').'"}';

		}

		$apiDesc =  '{ "products": ['.$apiDesc;


		if ($shippingHandling > 0)
		{
			$apiDesc .= ',{"itemnumber":"0","name":"Total Shipping & Handling and other fees","unitprice":"'.number_format($shippingHandling, 2, '.', '').'", "quantity":"1","amount":"'.number_format($shippingHandling, 2, '.', '').'"}';
		}

		$apiDesc .=  '] }';


        //Build urls back to our modules controller actions as required by the api.
        $oUrl = Mage::getModel('core/url');
        $apiHrefSuccess = $oUrl->getUrl("HostedPayment/standard/success");
        $apiHrefCancel = $oUrl->getUrl("HostedPayment/standard/Cancel");

        //Json Call
        $response = $api->beginPayment($payment,
        							   $apiDesc,
									   $apiHrefSuccess,
									   $apiHrefCancel,
									   Mage::helper('core')->decrypt($this->getConfigData('user_name')),
									   Mage::helper('core')->decrypt($this->getConfigData('password')),
									   $action,
        							   $this->getConfigData('Recurring'),
									   $this->getConfigData('test'),
									   $this->getConfigData('tokenserviceurl'),
									   $quoteId);
        if(!empty($response)){
            Mage::log("Successfully preparePayment with Checkout.com");
            $token = $response;
            $sessionCheckout->setData('PaymentToken',$token);
            $sessionCheckout->setData('trackid',$order->getIncrementId());
        	$sessionCheckout->setData('ProductDescription',$apiDesc);
        	$sessionCheckout->setData('VerifyKey', $this->getConfigData('verifykey'));
        }else{
           Mage::log("BeginPayment failed");
           //You can add error messages to the checkout process. These show up when you view the cart.
           $sessionCheckout->addError('An unrecoverable error occured while processing your payment information.');
           Mage::throwException('An unrecoverable error occured while processing your payment information.');
        }
        return $this;
    }
}