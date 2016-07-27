<?php

class T4U_Payfull_Model_Commission extends Mage_Sales_Model_Quote_Address_Total_Abstract {

    public function collect(Mage_Sales_Model_Quote_Address $address) {

        parent::collect($address);

        
        $this->_setAmount(0);
        $this->_setBaseAmount(0);

        $quote = $address->getQuote();
        if (!$quote->isVirtual() && $address->getAddressType() == 'billing') {
            return $this;
        }

        if (isset($_REQUEST['payment']) && $_REQUEST['payment'] != '') {
            $additional = $this->getCommission($_REQUEST["payment"]);
            $address->setGrandTotal($address->getGrandTotal() + $additional);
            $address->setBaseGrandTotal($address->getGrandTotal() + $additional);
        }

        return $this;
    }

    public function getCommission($data) {
        $quote = Mage::getModel('checkout/session')->getQuote();
        $totals = $quote->getTotals();
        $grand_total = 0;

        foreach($totals as $key=>$total){
            if($key == 'grand_total') continue;
            $grand_total += $total->getData('value');
        }

        $paymentHelper = new T4U_Payfull_Model_Payment;

        $banksInfo = $paymentHelper->banks();
        $banksInfo = $banksInfo['data'];

        $binInfo   = $paymentHelper->bin($data['cc_number']);
        $binInfo   = $binInfo['data'];

        $installments_commission = 0;
        foreach($banksInfo as $bank){
            if($bank['bank'] == $binInfo['bank_id']){
                foreach($bank['installments'] as $installment){
                    if($installment['count'] == $data['installment']){
                        $installments_commission = $installment['commission'];
                        $installments_commission = str_replace('%', '', $installments_commission);
                        break;
                    }
                }
            }
        }

        $subTotalValue = $grand_total * ($installments_commission/100);
        return $subTotalValue;
    }
}
