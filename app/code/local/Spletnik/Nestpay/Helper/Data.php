<?php

class Spletnik_Nestpay_Helper_Data extends Mage_Core_Helper_Abstract {
	function getPaymentGatewayUrl() {
		return Mage::getUrl('nestpay/payment/gateway', array('_secure' => false));
	}
}