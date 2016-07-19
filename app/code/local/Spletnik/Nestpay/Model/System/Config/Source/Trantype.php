<?php

class Spletnik_Nestpay_Model_System_Config_Source_Trantype {
	public function toOptionArray() {
		return array(
			array('value' => 'Auth', 'label' => 'Auth'),
			array('value' => 'PreAuth', 'label' => 'PreAuth'),
		);
	}
}