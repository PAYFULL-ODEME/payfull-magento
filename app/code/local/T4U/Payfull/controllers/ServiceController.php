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

        $result = $this->getPayfull()->banks();
        $this->sendJson($result);
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