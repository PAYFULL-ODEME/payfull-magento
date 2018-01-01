<?php
class T4U_Payfull_ServiceController extends Mage_Core_Controller_Front_Action
{

    protected function getPayfull()
    {
        return Mage::getSingleton('payfull/payment');
    }

    protected function sendJson($response)
    {
        $this->getResponse()->clearHeaders()->setHeader('Content-type','application/json',true);
        $this->getResponse()->setBody(json_encode($response));
    }

    public function testAction()
    {
        $result = $this->getPayfull()->banks();
        print_r($result);

        // $this->sendJson(['data'=>'some data']);
    }

    public function redirectAction()
    {
        $payfull = Mage::getSingleton('core/session')->getPayfull();
        $html = $payfull['html'];
        unset($payfull['html']);
        // Mage::getSingleton('core/session')->setPayfull($payfull);
        $order = Mage::getModel('sales/order')->load($payfull['order_id'], 'increment_id');
        $order->setTotalPaid(0);
        $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, "Waiting to complete 3D secure process.");
        $order->save();

        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        $quote->setIsActive(1)->save();

        echo $html;
        exit;

        // $this->sendJson(['data'=>'some data']);
    }

    public function responseAction()
    {

        $data = $this->getRequest()->getPost();

        $order_id   = isset($data['passive_data']) ? $data['passive_data'] : null;
        $session    = Mage::getSingleton('checkout/session');
        $order      = Mage::getModel('sales/order')->load($order_id, 'increment_id');
        $amount     = $order->getGrandTotal();
        $payment    = $order->getPayment();


        $dataToGetCommission = [];
        $dataToGetCommission['bank_id']     = $data['bank_id'];
        $dataToGetCommission['installment'] = $data['installments'];

        $commissionHelper = new T4U_Payfull_Model_Commission;
        $commissionValue  = $commissionHelper->getCommission($dataToGetCommission);

        if (isset($data['status']) && $data['status']) {

            // $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
            // $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
            // // $invoice->register();
            // $transactionSave = Mage::getModel('core/resource_transaction')
            //     ->addObject($invoice)
            //     ->addObject($invoice->getOrder())
            //     ->save()
            // ;
            $payment->setTransactionId($data['transaction_id'])
                ->setAmount($amount)
                // ->capture(null)
                ->setCurrencyCode($order->getBaseCurrencyCode())
                ->setPreparedMessage('')
                ->setParentTransactionId('')
                ->setShouldCloseParentTransaction(true)
                ->setIsTransactionClosed(true)
                ->registerCaptureNotification($amount)
            ;
            $order->setTotalPaid($amount);
            $order->setStatus('processing', false);
            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, "3D payment succeeded.");
            $order->save();
            $payment->save();
            // die('done');

            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            $quote->setIsActive(0)->save();

            Mage::getSingleton('checkout/session')->unsQuoteId();
            Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => false));
            return $this;
        } else {
            $error = isset($data['ErrorMSG']) ? $data['ErrorMSG'] : "3D payment failed.";
            $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, array(
                'code' =>  $data['ErrorCode'],
                'message' =>  $error,
            ));
            Mage::getSingleton('core/session')->addError(Mage::helper('payfull')->__("3D secure payment failed."));
            $order->cancel();
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, $error);
            $order->registerCancellation($error);
            $order->save();

            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(1)
                        ->setReservedOrderId(NULL)
                        ->save();
                $session->replaceQuote($quote);
            }
            //Unset data
            $session->unsLastRealOrderId();

            return $this->getResponse()->setRedirect(Mage::getUrl('checkout/onepage'));
        }
    }

    public function issuerAction()
    {
        $this->passIfAjax();
        $bin = $this->getRequest()->getParam('bin');
        $result = $this->getPayfull()->bin($bin);
        $this->sendJson($result);
    }

    public function banksAction()
    {
        $this->passIfAjax();

        //get info from API about extra instalments
        $total          = $this->getRequest()->getParam('total');
        $currency       = $this->getRequest()->getParam('currency');
        $bin            = $this->getRequest()->getParam('bin');

        $result = $this->getPayfull()->banks();
        $issuer = $this->getPayfull()->bin($bin);
        $bankId = isset($issuer['data']['bank_id'])?$issuer['data']['bank_id']:'';

        $bank_info = [];
        foreach($result['data'] as $index=>$temp) {
            if($temp['bank'] == $bankId) {
                $bank_info  = $temp;
                $bank_index = $index;

                break;
            }
        }
        $gateway = isset($bank_info['gateway'])?$bank_info['gateway']:'';

        $extraInstallmentsAndInstallmentsArr = [];
        $extra_installments_info 	         = $this->getPayfull()->extraInstallmentsList($currency);
        if(isset($extra_installments_info['data']['campaigns'])) {
            foreach($extra_installments_info['data']['campaigns'] as $extra_installments_row){
                if(
                    $extra_installments_row['bank_id']           == $bankId AND
                    $extra_installments_row['min_amount']        < ($total*$extra_installments_info['data']['exchange_rate']) AND
                    $extra_installments_row['status']            == 1 AND
                    $extra_installments_row['gateway']           == $gateway
                ){
                    $extraInstallmentsAndInstallmentsArr[$extra_installments_row['base_installments']] = true;
                }
            }
        }

        if(Mage::getStoreConfig('payment/payfull/use_extra_installment')){
            foreach($bank_info['installments'] as $justNormalKey=>$installment){
                if(isset($extraInstallmentsAndInstallmentsArr[$installment['count']])) $bank_info['installments'][$justNormalKey]['hasExtra'] = '1';
                else																   $bank_info['installments'][$justNormalKey]['hasExtra'] = '0';
            }
        }

        if(isset($bank_index)){
            $result['data'][$bank_index] = $bank_info;
        }

        $this->sendJson($result);
    }

    public function extraInstallmentsAction()
    {
        $this->passIfAjax();
        if(!Mage::getStoreConfig('payment/payfull/use_extra_installment')){
            $extra_installments_info = [];
            $extra_installments_info['extra_inst'] = '';
            $this->sendJson($extra_installments_info);
            return;
        }

        $total          = $this->getRequest()->getParam('total');
        $currency       = $this->getRequest()->getParam('currency');
        $installments   = $this->getRequest()->getParam('installments');
        $bankId         = $this->getRequest()->getParam('bankId');
        $gateway        = $this->getRequest()->getParam('gateway');

        $extra_installments_info = $this->getPayfull()->extraInstallments($total, $currency, $installments, $bankId, $gateway);
        $extra_installments_info['extra_inst'] = '';

        //no correct response
        if(isset($extra_installments_info['data']['campaigns'])) {
            foreach($extra_installments_info['data']['campaigns'] as $extra_installments_row){
                $extra_installments_info['extra_inst'] = isset($extra_installments_info['extra_inst'])?$extra_installments_info['extra_inst']:[];
                $extra_installments_info['extra_inst'][$extra_installments_row['extra_installments']] = $extra_installments_row['campaign_id'];
            }
        }
        $this->sendJson($extra_installments_info);
    }

    protected function passIfAjax()
    {
        if ($this->getRequest()->isXmlHttpRequest()) {
            return true;
        }
        if ($this->getParam('ajax') || $this->getParam('isAjax')) {
            return true;
        }
        throw new Exception("Cannot process non-ajax request.");
    }

}