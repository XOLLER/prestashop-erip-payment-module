<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author eComCharge <techsupport@bepaid.by>
*  @copyright  2016 eComCharge
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

include(dirname(__FILE__). '/../../config/config.inc.php');
include(dirname(__FILE__). '/../../init.php');

/* will include backward file */
include(dirname(__FILE__). '/begatewayerip.php');

$begatewayerip = new beGatewayERIP();
$error = false;
$instruction = '';

if (@$_REQUEST['action'] != 'callback') {

  /* Does the cart exist and is valid? */
  $cart = Context::getContext()->cart;

  if (!isset($_REQUEST['order_id']))
  {
  	Logger::addLog('Не передан номер заказа', 4);
  	die('Критическая ошибка: отсутствует номер заказа');
  }

  if (!Validate::isLoadedObject($cart))
  {
  	Logger::addLog('Ошибка загрузки содержимого корзины заказа '.(int)$_REQUEST['order_id'], 4);
  	die('Критическая ошибка с корзиной заказа '.(int)$_REQUEST['order_id']);
  }

  if ($cart->id != $_REQUEST['order_id'])
  {
  	Logger::addLog('Конфликт с идентификаторами корзины заказа',4);
  	die('Критическая конфликт с идентификаторами корзины заказа '.(int)$_REQUEST['order_id']);
  }

  $currency = new Currency((int)$cart->id_currency);
  $customer = new Customer((int)$cart->id_customer);

  if (!Validate::isLoadedObject($currency))
  {
  	Logger::addLog('Ошибка загрузки данных валюты',4);
  	die('Критическая ошибка во время загрузки данных валюты заказа '.(int)$_REQUEST['order_id']);
  }

  if (!Validate::isLoadedObject($customer))
  {
  	Logger::addLog('Ошибка загрузки данных покупателя',4);
  	die('Критическая ошибка во время загрузки данных покупателя заказа '.(int)$_REQUEST['order_id']);
  }

  if (!in_array($currency->iso_code, array('BYR', 'BYN')))
  {
  	Logger::addLog('Ошибка использования валюты');
  	die('Критическая ошибка: валюта ' . $currency->iso_code . ' не допустима для оплаты через ЕРИП');
  }

  $email = (isset($customer->email)) ? $customer->email : Configuration::get('PS_SHOP_EMAIL');

  $return_base_url=(Configuration::get('PS_SSL_ENABLED') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].__PS_BASE_URI__.'modules/'.$begatewayerip->name.'/validation.php?';
  $callbackurl = $return_base_url . 'action=callback';
  $callbackurl = str_replace('carts.local', 'webhook.begateway.com:8443', $callbackurl);

  $params = array(
    'request' => array(
    	'amount' => (int)($cart->getOrderTotal(true, Cart::BOTH) * pow(10, (int)$currency->decimals * _PS_PRICE_COMPUTE_PRECISION_)),
    	'currency' => $currency->iso_code,
      'description' => 'Оплата заказа '.(int)$_REQUEST['order_id'],
      'email' => $email,
      'ip' => $_SERVER['REMOTE_ADDR'],
      'order_id' => (int)$_REQUEST['order_id'],
      'notification_url' => $callbackurl,
      'payment_method' => array(
        'type' => 'erip',
        'account_number' => (int)$_REQUEST['order_id'],
        'service_no' => (int)Configuration::get('BEGATEWAYERIP_SERVICE_NO'),
        'service_info' => array('Оплата заказа '.(int)$_REQUEST['order_id'])
      )
    )
  );

  $url = 'https://' . (Configuration::get('BEGATEWAYERIP_DOMAIN_API')) . '/beyag/payments';

  /* Do the CURL request */
  $curl = curl_init($url);

  curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
  )) ;
  curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
  curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
  curl_setopt($curl, CURLOPT_TIMEOUT, 30);
  curl_setopt($curl, CURLOPT_USERPWD,
    Configuration::get('BEGATEWAYERIP_SHOP_ID') . ':' .
    Configuration::get('BEGATEWAYERIP_SHOP_KEY'));
  curl_setopt($curl, CURLOPT_POST, 0);
  curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));

  $response = curl_exec($curl);

  if (!$response) {
    Logger::addLog('Ошибка создания требования на оплату в ЕРИП: ' . curl_error($curl) . '(' . curl_errno($curl) . ')',4);
    curl_close($curl);
    die('Ошибка создания требования на оплату в ЕРИП');
  }

  curl_close($curl);

  $response = json_decode($response,true);

  if ($response == NULL) {
    Logger::addLog('Ошибка обработки ответа на создание требования на оплату в ЕРИП',4);
    die('Ошибка обработки ответа на создание требования на оплату в ЕРИП');
  }

  switch ($response['transaction']['status'])
  {
  	case 'pending':
      $message = $response['transaction']['message'];

      $instruction = implode(' ', $response['transaction']['erip']['instruction']);

  		$begatewayerip->validateOrder((int)$cart->id,
  			Configuration::get('EP_OS_WAITING'), 0 ,
  			$begatewayerip->displayName, $message, NULL, NULL, false, $customer->secure_key);
  		break ;

  	default:
  		$error_message = (isset($response['message']) && !empty($response['errors'])) ? urlencode(Tools::safeOutput($response['message'])) : 'Ошибка создания счёта в ЕРИП. Свяжитесь с администратором магазина.';
      $error = true;
  		$begatewayerip->validateOrder((int)$cart->id,
  			Configuration::get('PS_OS_ERROR'), 0 ,
  			$begatewayerip->displayName, $error_message, NULL, NULL, false, $customer->secure_key);
      Logger::addLog('Ошибка создания требования на оплату в ЕРИП: ' . $error_message, 4);
  }

  $auth_order = new Order($begatewayerip->currentOrder);

  $redirect_url = Context::getContext()->link->getPageLink('order-confirmation', null, null, array(
    'id_order' => $auth_order->id,
		'id_cart' => (int)$cart->id,
		'id_module' => (int)$begatewayerip->id,
		'key' => $auth_order->secure_key,
    'erip_error' => ($error) ? '1':'0',
    'erip_path' => $instruction,
  ));
  Tools::redirect($redirect_url);

} else {
  // process webhook notification
  if ($_SERVER['PHP_AUTH_USER'] != Configuration::get('BEGATEWAYERIP_SHOP_ID') ||
      $_SERVER['PHP_AUTH_PW']   != Configuration::get('BEGATEWAYERIP_SHOP_KEY'))
  {
  	Logger::addLog('Нотификация: не верные авторизационные данные', 4);
  	die('Критическая ошибка: не верные авторизационные данные нотификации');
  }

  $json = file_get_contents('php://input');
  $json = json_decode($json, true);

  if ($json == NULL)
  {
    Logger::addLog('Нотификация: ошибка декодирования JSON',4);
    die('Критическая ошибка с валидацией JSON');
  }

  $cart = new Cart((int)$json['transaction']['order_id']);

  if (!Validate::isLoadedObject($cart))
  {
    Logger::addLog('Нотификация: ошибка загрузки содержимого корзины заказа',4);
    die('Критическая ошибка с корзиной заказа');
  }

  $currency = new Currency(Currency::getIdByIsoCode($json['transaction']['currency']));
  $customer = new Customer((int)$cart->id_customer);

  if (!Validate::isLoadedObject($currency))
  {
    Logger::addLog('Нотификация: ошибка загрузки данных валюты',4);
    die('Критическая ошибка во время загрузки данных валюты заказа');
  }

  if (!Validate::isLoadedObject($customer))
  {
    Logger::addLog('Нотификация: ошибка загрузки данных покупателя',4);
    die('Критическая ошибка во время загрузки данных покупателя заказа');
  }

  $message = $json['transaction']['message'];

  $paid_amount = $json['transaction']['amount'];
  $paid_amount = $paid_amount / pow(10, $currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
  $paid_amount = Tools::ps_round($paid_amount, $currency->decimals);

  if ($json['transaction']['status'] == 'successful' || $json['transaction']['status'] == 'failed')
  {
    $status = Configuration::get('EP_OS_PAYMENT_VALID');

    if ($json['transaction']['status'] == 'failed') {
      $status = Configuration::get('EP_OS_PAYMENT_ERROR');
      $paid_amount = 0;
    }

		$begatewayerip->setTransactionDetail($json);
		$begatewayerip->validateOrder(
      (int)$cart->id,
  		$status,
      $paid_amount,
  		$begatewayerip->displayName,
      $message,
      array('transaction_id' => $json['transaction']['uid']),
      NULL,
      false,
      $customer->secure_key
    );
  }

  echo "OK";
}