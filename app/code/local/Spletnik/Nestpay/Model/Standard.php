<?php

class Spletnik_Nestpay_Model_Standard extends Mage_Payment_Model_Method_Abstract {
    const MISSING_ORDER = 100;
    const WRONG_HASH = 101;

    protected $liveUrl = 'https://testsecurepay.intesasanpaolocard.com/fim/est3dgate';
    protected $testUrl = 'https://testsecurepay.intesasanpaolocard.com/fim/est3dgate';

    protected $_code = 'nestpay';

    //protected $_infoBlockType = 'nestpay/info_nestpay';
    protected $_formBlockType = 'nestpay/form_nestpay';

    public function getOrderPlaceRedirectUrl() {
        return Mage::getUrl('nestpay/payment/redirect', array('_secure' => false));
    }

    public function getFormUrl() {
        if (Mage::getStoreConfig('payment/nestpay/test_mode')) {
            return $this->testUrl;
        } else {
            return $this->liveUrl;
        }
    }

    public function getResponseUrl() {
        return Mage::getUrl('nestpay/payment/response', array('_secure' => false));
    }

    public function isAvailable($quote = null) {
        $clientid = Mage::getStoreConfig('payment/nestpay/clientid');
        $storekey = Mage::getStoreConfig('payment/nestpay/storekey');

        return parent::isAvailable($quote) && trim($clientid) != '' && trim($storekey) != '';
    }
}