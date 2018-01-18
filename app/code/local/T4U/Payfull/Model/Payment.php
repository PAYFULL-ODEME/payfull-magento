<?php

class T4U_Payfull_Model_Payment extends Mage_Payment_Model_Method_Abstract
{

    protected $_code                    = 'payfull';
    protected $_formBlockType           = 'payfull/form_checkout';
    protected $_infoBlockType           = 'payfull/info_payment';
    protected $_canVoid                 = false;
    protected $_canOrder                = true;
    protected $_canRefund               = true;
    protected $_canCapture              = true;
    protected $_canAuthorize            = false;
    protected $_canRefundInvoicePartial = true;
    protected $_canCapturePartial       = true;
    protected $_canUseCheckout          = true;
    protected $_canSaveCc               = false;

    public function api()
    {
        return Mage::getSingleton('payfull/api');
    }

    protected function config($key)
    {
        return $this->getConfigData($key);
    }

    public function getConfigPaymentAction()
    {
        return ($this->getIs3DSecure() OR $this->getIsBkm()) ? 'order' :'authorize_capture';
    }

    protected function getIs3DSecure()
    {
        $info = $this->getInfoInstance();
        return $this->getConfigData('use3d_secure') ? $info->getUse3dSecure() : false;
    }

    protected function getIsBkm()
    {
        $info = $this->getInfoInstance();
        return $this->getConfigData('use_bkm') ? $info->getUseBkm() : false;
    }

    protected function getExtraInstallment()
    {
        $info = $this->getInfoInstance();
        return $this->getConfigData('use_extra_installment') ? $info->getExtraInstallment() : false;
    }

    public function assignData($data)
    {
        // Mage::throwException("assignData: ".print_r($data, 1));
        // return $this;

        $commission = array();
        $commission['rate'] = $data->getCommission();
        $commission['total'] = $data->getCommissionRate();

        $info = $this->getInfoInstance();

        if ($data->getInstallment()) {
            $info->setInstallment($data->getInstallment());
        }

        if ($data->getBankId()) {
            $info->setBankId($data->getBankId());
        }

        if ($data->getGateway()) {
            $info->setGateway($data->getGateway());
        }

        $info->setUse3dSecure($data->getUse3dSecure()==1);

        if ($data->getUseBkm()) {
            $info->setUseBkm($data->getUseBkm());
        }

        if ($data->getExtraInstallment()) {
            $info->setExtraInstallment($data->getExtraInstallment());
        }

        $info->setCcType($data->getCcType())
            ->setCcOwner($data->getCcOwner())
            ->setCcLast4(substr($data->getCcNumber(), -4))
            ->setCcNumber($data->getCcNumber())
            ->setCcCid($data->getCcCid())
            ->setCcExpMonth($data->getCcExpMonth())
            ->setCcExpYear($data->getCcExpYear())
            ->setCcSsIssue($data->getCcSsIssue())
            ->setCcSsStartMonth($data->getCcSsStartMonth())
            ->setCcSsStartYear($data->getCcSsStartYear())
        ;

        return $this;
    }
    
    public function getOrderPlaceRedirectUrl()
    {
        if($this->getIs3DSecure() OR $this->getIsBkm()) {
            return Mage::getUrl('payfull/service/redirect', array(
                '_secure' => false
            ));
        } else {
            return null;
        }
    }

    public function order(Varien_Object $payment, $amount)
    {

        if(!$this->getIs3DSecure() AND !$this->getIsBkm()) {
            Mage::throwException("Authorize method can be used only with 3D secure transaction or BKM Express.");
            return $this;
        }

        $order = $payment->getOrder();
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        
        $request = $this->buildSalePacket($payment, $amount);
        try {
            $this->validatePaymentData($request);
            $result = $this->callApi('Sale', $request, false);
            $isValid3DResponse = strpos($result, '<html') !== false ? true : false; // response has HTML content
            if($isValid3DResponse) {
                $payment->setIsTransactionClosed(false);
                $order->setTotalPaid(0)->save();
                Mage::getSingleton('core/session')->setPayfull([
                    'order_id'=>$order->getIncrementId(),
                    'secure'=>true,
                    'html'=>$result
                ]);
            } else {
                $result = Mage::helper('core')->jsonDecode($result);
                if($this->getIsBkm()){
                    $message = isset($result['ErrorMSG']) ? $result['ErrorMSG'] : "BKM Express transaction failed";
                }else{
                    $message = isset($result['ErrorMSG']) ? $result['ErrorMSG'] : "3D secure transaction failed";
                }

                Mage::throwException($message);
                return $this;
            }
        } catch (\Exception $ex) {
            Mage::throwException($ex->getMessage());            
        }

        return $this;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        if($this->getIs3DSecure() OR $this->getIsBkm()) {
            Mage::throwException("Capture method cannot be used with 3D secure transaction.");
            return $this;
        }

        $info = $this->getInfoInstance();
        $data = [];
        $data['cc_number']   = $info->getCcNumber();
        $data['installment'] = $info->getInstallment();

        $commissionHelper = new T4U_Payfull_Model_Commission;
        $commissionValue  = $commissionHelper->getCommission($data);

        $is3d  = $this->getIs3DSecure();
        $order = $payment->getOrder();

        $session = Mage::getSingleton('checkout/session');
        $request = $this->buildSalePacket($payment, $amount);
        $payment->setAmount(0);
        $quote = Mage::getSingleton('checkout/type_onepage')->getQuote();

        try {
            $this->validatePaymentData($request);
            $result = $this->callApi('Sale', $request, !$is3d);

            if(isset($result['status']) && $result['status']) {
                $payment->setTransactionId($result['transaction_id']);
                Mage::getSingleton('core/session')->setPayfull(['order_id'=>$order->getIncrementId(), 'secure'=>false]);
                $order->setTotalPaid($commissionValue);
            } else {
                $message = isset($result['ErrorMSG']) ? $result['ErrorMSG'] : "Payment transaction failed";
                Mage::throwException($message);
                return $this;
            }
        } catch (\Exception $ex) {
            Mage::throwException($ex->getMessage());            
        }
        $payment->setIsTransactionClosed(0);
        return $this;
    }

    public function refund(Varien_Object $payment, $amount)
    {
        $order = $payment->getOrder();
        $tx = $payment->getLastTransId();
        if(isset($tx)) {
            $result = $this->callApi('Return', [
                'transaction_id' => $tx,
                'total' => $amount
            ]);
            if(isset($result['status']) && $result['status']) {
                $payment->setTransactionId($result['transaction_id']);
                $payment->setIsTransactionClosed(1);
                $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,array(
                    'refund'=>'Refund transaction succeeded',
                    'amount'=> $amount,
                ));
            } else {
                $message = isset($result['ErrorMSG']) ? $result['ErrorMSG'] : "Refund transaction failed";
                Mage::throwException($message);
            }
        } else {
            $errorMsg = __('The order does not support refund operation.');
            Mage::throwException($errorMsg);
        }
        return $this;
    }

    public function bin($bin)
    {
        return $this->callApi('Get', [
            'get_param' => 'Issuer',
            'bin' => $bin
        ]);
    }
    
    public function banks()
    {
        return $this->callApi('Get', [
            'get_param' => 'Installments',
        ]);
    }

    public function extraInstallments($total, $currency, $installments, $bankId, $gateway)
    {
        return $this->callApi('Get', [
            'get_param'       => 'ExtraInstallments',
            "total"           => $total,
            "currency"        => $currency,
            "bank_id"         => $bankId,
            "gateway"         => $gateway,
            "installments"    => $installments,
        ]);
    }

    public function extraInstallmentsList($currency)
    {
        return $this->callApi('Get', [
            'get_param'       => 'ExtraInstallmentsList',
            "exchange_rate"   => 1,
            "currency"        => $currency,
        ]);
    }

    public function test()
    {
        $conf = $this->getConfigData('endpoint');
        //$conf = Mage::getSingleton('payment/config');
        // $o = $this->getConfigData(); 
        return print_r($conf, 1);
        return __METHOD__;
    }

    protected function buildSalePacket($payment, $total)
    {
        $order          = $payment->getOrder();
        $currency       = $order->getBaseCurrencyCode();
        $billAddress    = $order->getBillingAddress();
        $installment    = $this->getConfigData('use_installment') ? $payment->getInstallment() : 1;
        $use_bkm        = $this->getIsBkm();
        $return_url     = Mage::getUrl('payfull/service/response', array('_secure' => false));

		$store = Mage::app()->getStore();
		$payment_title = "{$store->getName()}: total $total [$currency]";

        $request = [
            "total" => $total,
            "cc_name" => $payment->getCcOwner(),
            "cc_number" => $payment->getCcNumber(),
            "cc_month" => $payment->getCcExpMonth(),
            "cc_year" => $payment->getCcExpYear(),
            "cc_cvc" => $payment->getCcCid(),
            "currency" => $currency,
            "installments" => $installment,
            "payment_title" => $payment_title,
            "customer_firstname" => $billAddress->getData('firstname'),
            "customer_lastname" => $billAddress->getData('lastname'),
            "customer_email" => $billAddress->getData('email'),
            "customer_phone" => $billAddress->getData('telephone'),
            "passive_data" => $order->getIncrementId(),
            "use3d" => 0,
        ];
        if ($installment > 1) {
            $request['bank_id'] = $payment->getBankId();
            $request['gateway'] = $payment->getGateway();
        }
        if ($this->getIs3DSecure()) {
            $request['use3d'] = 1;
            $request['return_url'] = $return_url;
        }
        if($use_bkm){
            unset($request['use3d']);
            unset($request['gateway']);

            $request['cc_name']       = '';
            $request['cc_number']     = '';
            $request['cc_month']      = '';
            $request['cc_year']       = '';
            $request['cc_cvc']        = '';
            $request['bank_id']       = 'BKMExpress';
            $request['return_url']    = $return_url;
            $request['installments']  = $this->getConfigData('use_installment') ? 1 : 0;
        }

        $extraInstallment = $this->getExtraInstallment();
        $extraInstallment = explode('-', $extraInstallment);
        if(isset($extraInstallment[0]) AND $extraInstallment[0]){
            $request['campaign_id'] = $extraInstallment[0];
        }

        return $request;
    }
    
    public function callApi($action, $data, $return_json=true)
    {
        $data['type'] = $action;
        $data['merchant'] = $this->config('username');
        $data['language'] = explode('_', Mage::app()->getLocale()->getLocaleCode())[0];
        $data['client_ip'] = Mage::helper('core/http')->getRemoteAddr();
        $data['hash'] = $this->hash($data, $this->config('password'));

        $content = $this->post($this->config('endpoint'), $data);
        
        if($return_json){
            return json_decode($content, true);
        }
        return $content;
    }

    protected function validatePaymentData($data)
    {
        try {
            if(!isset($data["bank_id"]) OR ($data["bank_id"] != 'BKMExpress')){
                $this->checkCCNumber($data["cc_number"]);
                $this->checkCCCVC($data["cc_number"], $data["cc_cvc"]);
                $this->checkCCEXPDate($data["cc_month"], $data["cc_year"]);
            }
        } catch (\Exception $ex) {
            Mage::throwException($ex->getMessage());
        }
    }

    protected function checkCCNumber($cardNumber){
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        $len = strlen($cardNumber);
        if ($len < 15 || $len > 16) {

        }else {
            switch($cardNumber) {
                case(preg_match ('/^4/', $cardNumber) >= 1):
                    break;
                case(preg_match ('/^5[1-5]/', $cardNumber) >= 1):
                    break;
                default:
                    throw new Exception(__('Please enter a valid credit card number.'));
                    break;
            }
        }
    }

    protected function checkCCCVC($cardNumber, $cvc){
        // Get the first number of the credit card so we know how many digits to look for
        $firstnumber = (int) substr($cardNumber, 0, 1);
        if ($firstnumber === 3){
            if (!preg_match("/^\d{4}$/", $cvc)){
                throw new Exception(__('Please enter a valid credit card verification number.'));
            }
        }else if (!preg_match("/^\d{3}$/", $cvc)){
            throw new Exception(__('Please enter a valid credit card verification number.'));
        }
        return true;
    }

    protected function checkCCEXPDate($month, $year){
        if(strtotime('01-'.$month.'-'.$year) <= time()){
            throw new Exception(__('Incorrect credit card expiration date.'));
        }
        return true;
    }

    protected function hash($data, $password) 
    {
        $message = '';
        ksort($data);
        foreach($data as $key=>$value) {
            $message .= strlen($value).$value;
        }
        $hash = hash_hmac('sha1', $message, $password);
        
        return $hash;
    }

    protected function post($url, $data=array())
    {
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_ENCODING       => "",
            CURLOPT_USERAGENT      => "curl",
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_CONNECTTIMEOUT => 120,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CUSTOMREQUEST  => "POST",
        );

        $curl = curl_init($url);
        curl_setopt_array($curl, $options);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));

        //ssl
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

        $content  = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if($content === false) {
            throw new Exception(strtr('Error occurred in sending transaction: {error}', array(
                '{error}' => $error,
            )));
        }

        return $content;
    }

}
