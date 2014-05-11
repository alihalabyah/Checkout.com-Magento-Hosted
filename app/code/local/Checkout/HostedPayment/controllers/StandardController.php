<?php
class Checkout_HostedPayment_StandardController extends Mage_Core_Controller_Front_Action
{
    /**
     * Get singleton of Checkout Session Model
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    protected function _isValidTrackID(){
        $uriToken = $this->getRequest()->getParam('trackid');
        $sessionToken = $this->_getApiTrackID();
        Mage::Log("Testing tokens(uri/session) $uriToken/$sessionToken");
        if($uriToken == $sessionToken){
            return true;
        }
        return false;
    }

    protected function _getApiTrackID(){
    	$sessionToken = Mage::getSingleton('checkout/session')->getData('trackid');
    	return $sessionToken;
    }

    protected function _getApiQuoteId(){
        $quoteId = Mage::getSingleton('checkout/session')->getData('apiQuoteId');
        Mage::log('Returned quoteId ' . $quoteId);
        return $quoteId;
    }

    protected  function _getApiOrderId(){
        $orderId = Mage::getSingleton('checkout/session')->getData('apiOrderId');
        Mage::log('Returned orderId ' . $orderId);
        return $orderId;
    }

    /**
    * Builds invoice for order
    */
    protected function _createInvoice()
    {
        if (!$this->_order->canInvoice()) {
            return;
        }
        //$invoice = $this->_order->prepareInvoice();
        //$invoice->register()->capture();
        //$this->_order->addRelatedObject($invoice);
    }

    /**
     * When a customer cancel payment from api
     */
    protected function _cancelAction()
    {
        Mage::Log('Called ' . __METHOD__);

        $session = Mage::getSingleton('checkout/session');

         /* @var $quote Mage_Sales_Model_Quote */
        $quote = $session->getQuote();
        $quote->setIsActive(false)->save();
        $quote->delete();

        $order = Mage::getModel('sales/order')->loadByIncrementId($this->getRequest()->getParam('trackid'));;
        $order->getId();
        $orderId = $this->_getApiOrderId();

        Mage::Log('Canceling order '.$orderId);
        if ($orderId) {
            if ($order->getId()) {
                $state = $order->getState();
                if($state == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT){
                    $order->cancel()->save();
                    Mage::getSingleton('core/session')->addNotice('Your order has been canceled.');
                }
            }
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * When Checkout.com returns
     * The order information at this point is in POST
     * variables.  However, you don't want to "process" the order until you
     * get validation from the Jsaon Call.
     */
    public function  successAction()
    {
        Mage::Log('Called ' . __METHOD__);
		$error = false;
		$errorMsg = "";
		$orderID = "";

        try{
        	$order=Mage::getModel('sales/order')->loadByIncrementId($this->getRequest()->getParam('trackid'));
        	$orderID = $order->getId();

        	$session = Mage::getSingleton('checkout/session');
        	$session->setQuoteId($orderID);

        	$session->setData('apiOrderId',$orderID);
        	$session->setData($this->getRequest()->getParam('trackid'));

        }catch (Exception $e){
        	Mage::throwException($e->getMessage(),$e->getCode());
        	$error = true;
        }

        $VerifyKey = "";
        $VerifyKey = Mage::helper('core')->decrypt(Mage::getSingleton('checkout/session')->getData('VerifyKey'));

        $api = new Checkout_HostedPayment_Model_Api();
        $isValidResponse = $api->isValidPayment($VerifyKey, $_GET["sig"]);

        if($isValidResponse)
        {
        	if($this->getRequest()->getParam('result') == 'Successful')
        	{
	        	if($this->getRequest()->getParam('responsecode') == '0')
	        	{
			        Mage::Log("Successful transaction");
		        	$state = $order->getState();

		        	if($state == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT){
		        		//Change the state of the order to pending and add comment.
		        		$msg = 'Payment completed via Checkout.com.';
		        		$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING ,true,$msg,false);
		        		$order->save();

		        		/* @var $quote Mage_Sales_Model_Quote */
		        		$quote = Mage::getSingleton('checkout/session')->getQuote();
		        		$quote->setIsActive(false)->save();
		        	}
	        	}
	        	else
	        	{
			        Mage::Log("Unsuccessful transaction - response code");
			        $errorMsg = "Unsuccessful transaction";
		        	$error = true;
	        	}
        	}
        	else
        	{
        		//echo "Unsuccessful transaction - result";
		        Mage::Log("Unsuccessful transaction");
		        $errorMsg = "Unsuccessful transaction";
	        	$error = true;
        	}
        }
        else
        {
	        Mage::Log("Invalid Response");
	        $errorMsg = "Invalid Response";
        	$error = true;
        }

		if ($error == false)
		{
        	$this->_redirect('checkout/onepage/success', array('_secure'=>true));
		}
		else
		{
	        if ($orderID=="")
	        {
	        	Mage::Log('Invalid transaction.');
        		$this->_redirect('checkout/cart');
	        }
	        else
	        {
	        	$this->cancelAction();
	        }
		}
    }
    /**
     * Handles 'falures' from api
     * Failure could occur if api system failure, insufficent funds, or system error.
     * @throws Exception
     */
    public function failureAction(){
        Mage::Log('Called ' . __METHOD__);
        $this->cancelAction();
    }

    public function cancelAction(){
        Mage::Log('Called ' . __METHOD__);
        $this->_cancelAction();
    }
}