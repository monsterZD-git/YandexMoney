<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))).'/config.core.php';

require_once MODX_CORE_PATH.'config/'.MODX_CONFIG_KEY.'.inc.php';
require_once MODX_CORE_PATH.'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('web');

$snippet = $modx->getObject('modSnippet', array('name' => 'YandexMoney'));
$config  = $snippet->getProperties();

if (!defined('YANDEXMONEY_PATH')) {
    define('YANDEXMONEY_PATH', MODX_CORE_PATH."components/yandexmoney/");
}

require_once YANDEXMONEY_PATH.'model/yandexmoney.class.php';

if (isset($_GET['fail']) && $_GET['fail'] == 1) {
    if ($res = $modx->getObject('modResource', $config['fail_page_id'])) {
        $modx->sendRedirect($modx->makeUrl($config['fail_page_id'], '', '', 'full'));
    }
    exit;
} elseif (isset($_GET['success']) && $_GET['success'] == 1) {
    if ($res = $modx->getObject('modResource', $config['success_page_id'])) {
        $modx->sendRedirect($modx->makeUrl($config['success_page_id'], '', '', 'full'));
    }
    exit;
} elseif (isset($_GET['return']) && $_GET['return'] == 1) {
    $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    if ($orderId !== 0) {
        $modx->addPackage('shopkeeper3', MODX_CORE_PATH."components/shopkeeper3/model/");
        $order = $modx->getObject('shk_order', array('id' => $orderId));
        if ($order) {
            $sql  = 'SELECT payment_id FROM '.$modx->getTableName('YandexMoneyKassaPayment').' WHERE `order_id` = :orderId';
            $stmt = $modx->prepare($sql);
            $stmt->bindValue(':orderId', $orderId, \PDO::PARAM_INT);
            $stmt->execute();
            $dataSet = $stmt->fetch();
            $stmt->closeCursor();
            if (!empty($dataSet)) {
                $paymentId = $dataSet[0];
                $ym        = new Yandexmoney($modx, $config);
                $payment   = $ym->getPaymentById($paymentId);
                if ($payment !== null) {
                    if ($payment->getPaid()) {
                        if ($payment->getStatus() === \YandexCheckout\Model\PaymentStatus::WAITING_FOR_CAPTURE) {
                            $ym->capturePayment($payment, false);
                        }
                        $paymentInfo = $ym->getPaymentById($paymentId);
                        
                        if ($paymentInfo->getStatus() == \YandexCheckout\Model\PaymentStatus::SUCCEEDED) {
                            $ym->updateOrderStatus($order, $config['ya_billing_status']);
                        }
                        if ($res = $modx->getObject('modResource', $config['success_page_id'])) {
                            $modx->sendRedirect($modx->makeUrl($config['success_page_id'], '', '', 'full'));
                        }
                        exit;
                    }
                }
            }
        }
    }
    if ($res = $modx->getObject('modResource', $config['fail_page_id'])) {
        $modx->sendRedirect($modx->makeUrl($config['fail_page_id'], '', '', 'full'));
    }
    exit;
} elseif (isset($_GET['notification']) && $_GET['notification'] == 1) {
    $source = file_get_contents('php://input');
    $ym     = new Yandexmoney($modx, $config);
    if (empty($source)) {
        $ym->log('notice', 'Call capture notification controller without body');
        header('HTTP/1.1 400 Empty notification object');

        return;
    }
    $ym->log('info', 'Notification body: '.$source);
    $json = json_decode($source, true);
    
    if (empty($json)) {
        if (json_last_error() === JSON_ERROR_NONE) {
            $message = 'empty object in body';
        } else {
            $message = 'invalid object in body: '.json_last_error_msg();
        }
        $ym->log('warning', 'Invalid parameters in capture notification controller - '.$message);
        header('HTTP/1.1 400 Invalid notification object');

        return;
    }
    try {
        if ($json['event'] == \YandexCheckout\Model\NotificationEventType::PAYMENT_WAITING_FOR_CAPTURE) {
            $object = new YandexCheckout\Model\Notification\NotificationWaitingForCapture($json);
            $ym->log('error', 'Notification waiting for capture init');
        } else {
            $object = new YandexCheckout\Model\Notification\NotificationSucceeded($json);
            $ym->log('error', 'Notification succeeded init');
        }
    } catch (\Exception $e) {
        $ym->log('error', 'Invalid notification object - '.$e->getMessage());
        header('HTTP/1.1 500 Server error: '.$e->getMessage());

        return;
    }
    $payment = $ym->getPaymentById($object->getObject()->getId());
    if ($payment === null) {
        $ym->log('error', 'Payment not found ');
        echo json_encode(array('success' => false, 'reason' => 'Payment not found'));
        exit();
    }
    $result = $ym->capturePayment($object->getObject());
    if (!$result) {
        header('HTTP/1.1 500 Server error 1');
        exit();
    }
    if ($result->getStatus() === \YandexCheckout\Model\PaymentStatus::SUCCEEDED) {
        try {
            $orderId = $object->getObject()->getMetadata()->offsetGet('order_id');
            $modx->addPackage('shopkeeper3', MODX_CORE_PATH."components/shopkeeper3/model/");
            $order = $modx->getObject('shk_order', array('id' => $orderId));
            
            //$res   = $ym->updateOrderStatus($order, $config['ya_billing_status']);
            $res   = $ym->updateOrderStatus($order, 6);
            if ($res == 1) {
                $a = $modx->runSnippet('UpdatePromo', array('order_id'=>$orderId));
                $b = $modx->runSnippet('UpdateTovar', array('order_id'=>$orderId));
                $c = $modx->runSnippet('SendEmail', array('order_id'=>$orderId));
            }
            
        } catch (Exception $e) {
            $ym->log('info', var_export($e, true));
        }
    } else {
        $ym->log('info', 'Failed');
    }
    

    echo json_encode(array('success' => ($result->getStatus() === \YandexCheckout\Model\PaymentStatus::SUCCEEDED)));
    exit();
}

$ym = new Yandexmoney($modx, $config);
$ym->ProcessResult();
