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
        $order->sendNewOrderEmail();
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

        /**
         * Change state
         */

        $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, 'Redirected.');
        $order->save();

        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function responseAction() {
        $currentDateTime = Mage::getModel('core/date')->date('d-m-Y H:i:s') . " ";
        file_put_contents(Mage::getBaseDir("log") . "/payment.log", $currentDateTime . print_r($_REQUEST, true), FILE_APPEND);

        /*if (!$this->checkHash($this->getRequest()->get('HASHPARAMSVAL'), $this->getRequest()->get('HASH'))) {
            Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true, 'code' => Spletnik_Nestpay_Model_Standard::WRONG_HASH));

            return;
        }*/
        if ($this->getRequest()->get("Response") == "Approved" && $this->getRequest()->get("ReturnOid")) {
            /**
             * Order succeeded
             */

            $order = new Mage_Sales_Model_Order();
            $orderId = $this->getRequest()->get("oid");
            $order->loadByIncrementId($orderId);
            if ($order->isEmpty()) {
                Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/error', array('_secure' => true));

                return;
            }

            /**
             * Save transaction info
             */
            $transactionID = $this->getRequest()->get("TransId");
            $comment = $this->getRequest()->get("ErrMsg");

            $payment = $order->getPayment();
            $payment->setTransactionId($transactionID);

            switch ($this->getRequest()->get("trantype")) {
                case 'Auth':
                    $type = 'authorization';
                    break;
                case 'PreAuth':
                    $type = 'capture';
                    break;
                default:
                    $type = 'order';
                    break;
            }

            $transaction = $payment->addTransaction($type, null, false, $comment);
            $transaction->setParentTxnId($transactionID);
            $transaction->setIsClosed(true);
            $transaction->save();
            $order->save();

            /**
             * Change state
             */

            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true, 'Payment Success.');
            $order->save();

            /**
             * Send email
             */

            $data['order'] = $order;
            $data['payment_html'] = Mage::getStoreConfig('payment/nestpay/title');
            $data['orderID'] = $this->getRequest()->get("oid");
            $data['AuthCode'] = $this->getRequest()->get("AuthCode");
            $data['xid'] = $this->getRequest()->get("xid");
            $data['Response'] = $this->getRequest()->get("Response");
            $data['ProcReturnCode'] = $this->getRequest()->get("ProcReturnCode");
            $data['TransId'] = $this->getRequest()->get("TransId");
            $data['EXTRA_TRXDATE'] = $this->getRequest()->get("EXTRA_TRXDATE");

            $emailTemplate = Mage::getModel('core/email_template')->loadDefault('nestpay_payment_received');
            //$processedTemplate = utf8_decode($emailTemplate->getProcessedTemplate($data));
            $processedTemplate = mb_convert_encoding($emailTemplate->getProcessedTemplate($data), 'ISO-8859-1', 'UTF-8');


            /*$mail = Mage::getModel('core/email')
                ->setToName($order->getBillingAddress()->getName())
                ->setToEmail($order->getBillingAddress()->getEmail())
                ->setBody($processedTemplate)
                ->setSubject('Payment received  #' . $orderId)
                ->setFromEmail(Mage::getStoreConfig('trans_email/ident_sales/email'))
                ->setFromName(Mage::getStoreConfig('trans_email/ident_sales/name'))
                ->setType('html');
            $mail->send();*/

            $mail = new Zend_Mail('utf-8');
            $mail->setBodyHtml($processedTemplate);

            $mail->setFrom(Mage::getStoreConfig('trans_email/ident_sales/email'), Mage::getStoreConfig('trans_email/ident_sales/name'))
                ->addTo($order->getBillingAddress()->getEmail(), $order->getBillingAddress()->getName())
                ->setSubject($this->__('Payment successful #') . $orderId);
            $mail->send();

            //Mage_Core_Controller_Varien_Action::_redirect('nestpay/payment/success', array('_secure' => true));
            $this->_forward('success', NULL, NULL, $data);
            //Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => true));
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
             * Save transaction info
             */
            $transactionID = $this->getRequest()->get("TransId");
            $comment = $this->getRequest()->get("ErrMsg");
            $payment = $order->getPayment();
            $payment->setTransactionId($transactionID);
            $transaction = $payment->addTransaction('void', null, false, $comment);
            if ($transaction != null) {
                $transaction->setParentTxnId($transactionID);
                $transaction->setIsClosed(true);
                $transaction->save();
            }
            $order->save();

            /**
             * Reuse last quote
             */
            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            $quote->setIsActive(true)->setReservedOrderId(NULL)->save();
            $session->replaceQuote($quote);


            /**
             * Send email
             */

            $data['order'] = $order;
            $data['payment_html'] = Mage::getStoreConfig('payment/nestpay/title');
            $data['orderID'] = $this->getRequest()->get("oid");
            $data['AuthCode'] = $this->getRequest()->get("AuthCode");
            $data['xid'] = $this->getRequest()->get("xid");
            $data['Response'] = $this->getRequest()->get("Response");
            $data['ProcReturnCode'] = $this->getRequest()->get("ProcReturnCode");
            $data['TransId'] = $this->getRequest()->get("TransId");
            $data['EXTRA_TRXDATE'] = $this->getRequest()->get("EXTRA_TRXDATE");

            $emailTemplate = Mage::getModel('core/email_template')->loadDefault('nestpay_payment_failed');
            //$processedTemplate = utf8_decode($emailTemplate->getProcessedTemplate($data));
            $processedTemplate = $emailTemplate->getProcessedTemplate($data);

            /**
             * Send email
             */

            $mail = new Zend_Mail('uft-8');
            //$mail->setHeaderEncoding(Zend_Mime::ENCODING_BASE64);
            $mail->setBodyHtml($processedTemplate);

            $mail->setFrom(Mage::getStoreConfig('trans_email/ident_sales/email'), Mage::getStoreConfig('trans_email/ident_sales/name'))
                ->addTo($order->getBillingAddress()->getEmail(), $order->getBillingAddress()->getName())
                ->setSubject($this->__('Payment failed #') . $orderId);

            $mail->send();


            /**
             * Redirect to error page
             */
            $this->_forward('error', NULL, NULL, $data);
        }
    }

    public function errorAction() {
        $orderID = $this->getRequest()->getParam('orderID');
        $AuthCode = $this->getRequest()->getParam('AuthCode');
        $xid = $this->getRequest()->getParam('xid');
        $Response = $this->getRequest()->getParam('Response');
        $ProcReturnCode = $this->getRequest()->getParam('ProcReturnCode');;
        $TransId = $this->getRequest()->getParam('TransId');
        $EXTRA_TRXDATE = $this->getRequest()->getParam('EXTRA_TRXDATE');

        $this->loadLayout();
        $block = $this->getLayout()
            ->createBlock('Mage_Core_Block_Template', 'nestpay', array('template' => 'nestpay/error.phtml'))
            ->setData('orderID', $orderID)
            ->setData('AuthCode', $AuthCode)
            ->setData('xid', $xid)
            ->setData('Response', $Response)
            ->setData('ProcReturnCode', $ProcReturnCode)
            ->setData('TransId', $TransId)
            ->setData('EXTRA_TRXDATE', $EXTRA_TRXDATE);
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function successAction() {
        $orderID = $this->getRequest()->getParam('orderID');
        $AuthCode = $this->getRequest()->getParam('AuthCode');
        $xid = $this->getRequest()->getParam('xid');
        $Response = $this->getRequest()->getParam('Response');
        $ProcReturnCode = $this->getRequest()->getParam('ProcReturnCode');;
        $TransId = $this->getRequest()->getParam('TransId');
        $EXTRA_TRXDATE = $this->getRequest()->getParam('EXTRA_TRXDATE');

        $this->loadLayout();
        $block = $this->getLayout()
            ->createBlock('Mage_Core_Block_Template', 'nestpay', array('template' => 'nestpay/success.phtml'))
            ->setData('orderID', $orderID)
            ->setData('AuthCode', $AuthCode)
            ->setData('xid', $xid)
            ->setData('Response', $Response)
            ->setData('ProcReturnCode', $ProcReturnCode)
            ->setData('TransId', $TransId)
            ->setData('EXTRA_TRXDATE', $EXTRA_TRXDATE);

        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    private function checkHash($plaintext, $hash) {
        $storekey = str_replace("|", "\\|", str_replace("\\", "\\\\", Mage::getStoreConfig('payment/nestpay/storekey')));

        $calculatedHash = base64_encode(pack('H*', hash('sha512', $plaintext . '|' . $storekey)));

        if ($hash != $calculatedHash) {
            return false;
        }

        return true;
    }
}