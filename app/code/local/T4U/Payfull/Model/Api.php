<?php

class T4U_Payfull_Model_Api {
    protected $_config;

    public function & getConfig() {
        if(empty($this->_config)) {
            $attrs = ['endpoint'];
            foreach ($attrs as $key) {
                $this->_config[$key] = Mage::getStoreConfig('payment/payfull/'.$key);
            }
        }
        return $this->_config;
    }

    public function test() {
        $config = $this->getConfig();
        $configData = $this->getMethod()->getData();
        return $configData;
    }
}
