<?php

class Spletnik_Nestpay_Block_Form_Nestpay extends Mage_Payment_Block_Form {
	protected function _construct() {
		parent::_construct();
		$this->setTemplate('nestpay/form/nestpay.phtml');
	}
}