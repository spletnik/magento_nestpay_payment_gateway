<?php

class Spletnik_Nestpay_PaymentController extends Mage_Core_Controller_Front_Action {
	public function gatewayAction() {
		if ($this->getRequest()->get("orderId")) {
			$arr_querystring = array(
				'flag'    => 1,
				'orderId' => $this->getRequest()->get("orderId"),
			);

			Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/response', array('_secure' => false, '_query' => $arr_querystring));
		}
	}

	public function redirectAction() {
		$order = new Mage_Sales_Model_Order();
		$orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();

		if (!isset($orderId) || is_null($orderId)) {
			Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true, 'code' => Spletnik_Nestpay_Model_Standard::MISSING_ORDER));

			return;
		}

		$order->loadByIncrementId($orderId);
		$order->addStatusHistoryComment("Redirecting to nestpay...")->save();


		$clientid = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getStoreConfig('payment/nestpay/clientid')));
		$oid = str_replace("|", "\\|", str_replace("\\", "\\\\", $order->getIncrementId()));
		$amount = str_replace("|", "\\|", str_replace("\\", "\\\\", $order->getGrandTotal()));
		$okurl = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getUrl('nestpay/payment/response', array('_secure' => true))));
		$failurl = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getUrl('nestpay/payment/response', array('_secure' => true))));
		$trantype = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getStoreConfig('payment/nestpay/trantype')));
		$instalment = '';

		$rnd = microtime();

		$currency = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getStoreConfig('payment/nestpay/currency')));
		$storekey = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getStoreConfig('payment/nestpay/storekey')));
		$lang = "en";
		$storetype = "3D_PAY_HOSTING";

		$plaintext = $clientid . '|' . $oid . '|' . $amount . '|' . $okurl . '|' . $failurl . '|' . $trantype . '|' . $instalment . '|' . $rnd . '||||' . $currency . '|' . $storekey;

		$hashValue = hash('sha512', $plaintext);
		$hash = base64_encode(pack('H*', $hashValue));

		$this->loadLayout();
		$block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'nestpay', array('template' => 'nestpay/redirect.phtml'));

		$block->assign(array(
			'clientid'  => $clientid,
			'storetype' => $storetype,
			'hash'      => $hash,
			'trantype'  => $trantype,
			'ammount'   => $amount,
			'currency'  => $currency,
			'oid'       => $oid,
			'okUrl'     => $okurl,
			'failUrl'   => $failurl,
			'lang'      => $lang,
			'encoding'  => 'utf-8',
			'rnd'       => $rnd,
		));

		$this->getLayout()->getBlock('content')->append($block);
		$this->renderLayout();
	}

	public function responseAction() {
		if (!$this->checkHash($this->getRequest()->get('HASHPARAMSVAL'), $this->getRequest()->get('HASH'))) {
			Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true, 'code' => Spletnik_Nestpay_Model_Standard::WRONG_HASH));

			return;
		}
		file_put_contents("payment.log", print_r($_REQUEST, true), FILE_APPEND);
		if ($this->getRequest()->get("Response") == "Approved" && $this->getRequest()->get("ReturnOid")) {
			/**
			 * Order succeeded
			 */

			$order = new Mage_Sales_Model_Order();
			$orderId = $this->getRequest()->get("ReturnOid");
			$order->loadByIncrementId($orderId);
			if ($order->isEmpty()) {
				Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true));

				return;
			}

			$order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, 'Payment Success.');
			$order->save();

			//Mage::getSingleton('checkout/session')->unsQuoteId();
			Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => true));
		} else {
			/**
			 * Get current session
			 */
			$session = Mage::getSingleton('checkout/session');
			/**
			 * Order failed ...
			 */
			$order = new Mage_Sales_Model_Order();
			$orderId = $this->getRequest()->get("oid");
			$order->loadByIncrementId($orderId);
			if ($order->isEmpty()) {
				Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true));

				return;
			}

			/**
			 * Order failed, set status accordingly
			 */
			if ($order->canCancel()) {
				$order->cancel();
			}
			$order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, "Payment failed: " . $this->getRequest()->get('mdErrorMsg') . ". - " . $this->getRequest()->get('Response'), false)->save();

			/**
			 * Reuse last quote
			 */
			$quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
			$quote->setIsActive(true)->setReservedOrderId(NULL)->save();
			$session->replaceQuote($quote);

			// Redirect to error page
			Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true));
		}
	}

	private function checkHash($plaintext, $hash) {
		$storekey = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getStoreConfig('payment/nestpay/storekey')));

		$calculatedHash = base64_encode(pack('H*', hash('sha512',$plaintext . '|' . $storekey)));

		if ($hash != $calculatedHash) {
			return false;
		}

		return true;
	}

	public function errorAction() {
		$this->loadLayout();
		$block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'nestpay', array('template' => 'nestpay/error.phtml'));
		$this->getLayout()->getBlock('content')->append($block);
		$this->renderLayout();
	}
}