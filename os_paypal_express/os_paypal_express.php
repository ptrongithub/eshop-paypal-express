<?php
/**
 * @version		0.1.0
 * @package		Joomla
 * @subpackage	EShop
 * @author  	Joshua E Vines
 * @copyright	Copyright © 2017 Phoenix Technological Research
 * @license		GNU/GPL, see LICENSE.php
 */
// no direct access
defined('_JEXEC') or die();
jimport('paypal_php_sdk.autoload');
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Address;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Links;
use PayPal\Api\Payee;
use PayPal\Api\Payer;
use PayPal\Api\PayerInfo;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\ShippingAddress;
use PayPal\Api\Transaction;
use PayPal\Api\VerifyWebhookSignature;
use PayPal\Api\VerifyWebhookSignatureResponse;
use PayPal\Api\Webhook;
use PayPal\Api\WebhookEvent;

class os_paypal_express extends os_payment
{
	private $apiContext;
	private $payee;
	private $webhookId;
	private $approvalUrl;
	
	function PayPalError($e) {
		$err = "";
		do {
			if (is_a($e, "PayPal\Exception\PayPalConnectionException")) {
				$data = json_decode($e->getData(),true);
				$err .= $data['name'] . " - " . $data['message'] . "<br>";
				if (isset($data['details'])) {
					$err .= "<ul>";
					foreach ($data['details'] as $details) {
						$err .= "<li>". $details['field'] . ": " . $details['issue'] . "</li>";
					}
					$err .= "</ul>";
				}
			} else {
				//some other type of error
				$err .= sprintf("%s:%d %s (%d) [%s]\n", $e->getFile(), $e->getLine(), $e->getMessage(), $e->getCode(), get_class($e));
			}
		} while($e = $e->getPrevious());
		return $err;
	} // END FUNCTION PayPalError
	public function logIpn($extraData = null)
	{
		if (!$this->params->get('ipn_log')) return;
		$text = '[' . date('Y/m/d g:i A') . '] - ';
		$text .= "Log Data From : ".$this->title." \n";
		foreach ($this->postData as $key => $value)
		{
			$text .= "$key=$value, ";
		}
		if (strlen($extraData))
		{
			$text .= $extraData;
		}
		$ipnLogFile = JPATH_COMPONENT . '/ipn_' . $this->getName() . '.txt';
		error_log($text."\n\n", 3, $ipnLogFile);
	}

	/***********************************************************************************************
	 * Constructor functions, init some parameter
	 *
	 * @param object $config
	 */
	public function __construct($params)
	{
		parent::__construct($params, $config);

		// Create and define merchant
		// Create Payee object
		$this->payee = new PayPal\Api\Payee();

		// Set PayPal API config options
		$this->mode = $params->get('paypal_mode');
		if ($this->mode == 'live')
		{
			$this->apiContext = new PayPal\Rest\ApiContext(new PayPal\Auth\OAuthTokenCredential(
				$params->get('client_id_l'), $params->get('secret_l') ));
			$this->apiContext->setConfig( array( 'mode' => 'live',
				'http.ConnectionTimeOut' => $params->get('timeout_l'),
				'log.LogEnabled' => $params->get('log_enabled_l'),
				'log.FileName' => $params->get('log_path_l').'PayPal.log',
				'log.LogLevel' => $params->get('log_level_l') ) );
			$this->payee->setMerchantId($params->get('merchant_id_l'));
			//	->setEmail($params->get('email_l'));
			$this->url = 'https://www.paypal.com/cgi-bin/webscr';
		}
		else if ($this->mode == 'sandbox')
		{
			$this->apiContext = new PayPal\Rest\ApiContext(new PayPal\Auth\OAuthTokenCredential(
				$params->get('client_id_s'), $params->get('secret_s') ));
			$this->apiContext->setConfig( array( 'mode' => 'sandbox',
				'http.ConnectionTimeOut' => $params->get('timeout_s'),
				'log.LogEnabled' => $params->get('log_enabled_s'),
				'log.FileName' => $params->get('log_path_s').'PayPal.log',
				'log.LogLevel' => $params->get('log_level_s') ) );
			$this->payee->setMerchantId($params->get('merchant_id_s'));
			//	->setEmail($params->get('email_s'));
			$this->url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
		}
		$webhookId = '4BL56451WH155452T';
	} // END FUNCTION __constructor

	/***********************************************************************************************
	 * Process Payment
	 *
	 * @param array $params
	 */
	public function processPayment($data)
	{
		$paymentCountryInfo = EshopHelper::getCountry($data['payment_country_id']);
		$paymentZoneInfo = EshopHelper::getZone($data['payment_zone_id']);
		$shippingCountryInfo = EshopHelper::getCountry($data['shipping_country_id']);
		$shippingZoneInfo = EshopHelper::getZone($data['shipping_zone_id']);

		$rate = 1;

		/* Use selected PayPal API namespaces */

		/**
		 * Define payer
		 * setPaymentMethod
		 * payerInfo, billingAddress
		 */
		// Create Payer object
		$payer = new Payer();
		// Payment method is via PayPal. Take the customer to PayPal for processing.
		$payer->setPaymentMethod("paypal");
		// Create billingAddress as Address object and fill with customer's billing address.
		$billingAddress = new Address();
		$billingAddress->setLine1($data['payment_address_1'])
			->setLine2($data['payment_address_2'])
			->setCity($data['payment_city'])
			->setState($paymentZoneInfo->zone_code)
			->setPostalCode($data['payment_postcode'])
			->setCountryCode($paymentCountryInfo->iso_code_2)
			;
		// Create PayerInfo object, populate with customer's billing
		// info (name, billingAddress, phone, email)
		$payerInfo = new PayerInfo();
		$payerInfo->setFirstName($data['payment_firstname'])
			->setLastName($data['payment_lastname'])
			->setBillingAddress($billingAddress)
			//->setPhone($data['telephone'])
			->setEmail($data['email'])
			;
		// Assign payerInfo to payer.
		$payer->setPayerInfo($payerInfo);

		/**
		 * List of items sold and their details
		 * Add shipping address
		 */
		$itemList = new ItemList();
		// $tax = 0;
		// $subtotal = 0;
		foreach ($data['products'] as $product)
		{
			if ($product['product_sku']) {
				$item = new Item();
				$item->setName($product['product_name'])
					->setSku($product['product_sku'])
					->setQuantity((string)$product['quantity'])
					->setPrice(number_format(round($product['price'] * $rate, 2) , 2 , "." , "," ))
					->setTax(number_format($product['tax'] , 2 , "." , "," ))
					->setCurrency($data['currency_code'])
					;
				$itemList->addItem($item);
				// $tax += $product['tax'];
				// $subTotal += $product['quantity'] * round($product['price'] * $rate, 2)
			}
		}
		$shippingAddress = new ShippingAddress();
		$shippingAddress->setRecipientName($data['shipping_firstname'].' '.$data['shipping_lastname'])
			->setLine1($data['shipping_address_1'])
			->setLine2($data['shipping_address_2'])
			->setCity($data['shipping_city'])
			->setState($shippingZoneInfo->zone_code)
			->setPostalCode($data['shipping_postcode'])
			->setCountryCode($shippingCountryInfo->iso_code_2)
			;
		$itemList->setShippingAddress($shippingAddress);

		// Find totals
		//$data['totals'][0]['name'];
		$totals = array(
			'subTotal' => 0,
			'shipping' => 0,
			'tax' => 0,
			'total' => 0,
			);
		foreach ($data['totals'] AS $total)
		{
			if ($total['name'] == 'sub_total') {
				$totals['subTotal'] += $total['value'];
			}
			else if ($total['name'] == 'shipping') {
				$totals['shipping'] += $total['value'];
			}
			else if ($total['name'] == 'tax') {
				$totals['tax'] += $total['value'];
			}
			else if ($total['name'] == 'total') {
				$totals['total'] += $total['value'];
			}
		}

		$details = new Details();
		$details->setShipping(number_format($totals['shipping'] , 2 , "." , "," ))
			->setTax(number_format($totals['tax'] , 2 , "." , "," ))
			->setSubtotal(number_format($totals['subTotal'] , 2 , "." , "," ))
			;

		$amount = new Amount();
		$amount->setCurrency($data['currency_code'])
			->setTotal(number_format($data['total'] , 2 , "." , "," ))
			->setDetails($details)
			;

		$transaction = new Transaction();
		$transaction->setAmount($amount)
			->setCustom($data['order_id'])
			->setPayee($this->payee)
			->setItemList($itemList)
			->setInvoiceNumber((string)$data['invoice_number'])
			->setNotifyUrl(JUri::base().'index.php?option=com_eshop&task=checkout.verifyPayment&payment_method=os_paypal_preferred')
			;
		$redirectUrls = new RedirectUrls();
		$redirectUrls
			->setReturnUrl((JUri::base().'index.php?option=com_eshop&task=checkout.verifyPayment&payment_method=os_paypal_preferred&ep=execute'))
			->setCancelUrl(JUri::base().'index.php?option=com_eshop&view=checkout&layout=cancel&id='.$data['order_id'])
			;
		$payment = new Payment();
		$payment->setIntent("sale")
			->setPayer($payer)
			->setRedirectUrls($redirectUrls)
			->addTransaction($transaction)
			;
		if (!$this->redirectHeading)
		{
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			$query->select('title')
				->from('#__eshop_payments')
				->where('name = "' . $this->name . '"')
				;
			$db->setQuery($query);
			$this->redirectHeading = JText::sprintf('ESHOP_REDIRECT_HEADING', JText::_($db->loadResult()))
			;
		}
	?><div class="eshop-heading"><?php echo  $this->redirectHeading; ?></div><?php
		try {
			$payment->create($this->apiContext);
		} catch (Exception $ex) {
			echo $this->PayPalError($ex);
		}
		$approvalUrl = $payment->getApprovalLink();
		if(isset($approvalUrl)) {
			JFactory::getApplication()->redirect($approvalUrl);
		}
		/**
		 * Processing stops here. PayPal returns the user to the site with PayerID. Another
		 * function will be needed to process this data and call Payment->execute()
		 */
		return;
	} // END FUNCTION processPayment
	
	/***********************************************************************************************
	 * Execute the PayPal payment
	 */
	public function executePayment()
	{
		$jinput = JFactory::getApplication()->input;
		// get URL data and retrieve Payment
		$paymentId = $jinput->get('paymentId');
		$payerId = $jinput->get('PayerID');
		$payment = Payment::get($paymentId, $this->apiContext);

		// create paymentExecution
		$paymentExecution = new PaymentExecution();
		$paymentExecution->setPayerId($payerId);

		// Execute the payment
		$payment->execute($paymentExecution, $this->apiContext);

		// Redirect to payment completed page.
		JFactory::getApplication()->redirect(JUri::base().'index.php?option=com_eshop&view=checkout&layout=complete');
	}// END FUNCTION executePayment

	/***********************************************************************************************
	 * Validate the IPN data from PayPal to our server
	 *
	 * @return string
	 */
	protected function validate($inputData)
	{
		$errNum = "";
		$errStr = "";
		$urlParsed = parse_url($this->url);
		$host = $urlParsed['host'];
		$path = $urlParsed['path'];
		$postString = '';
		$response = '';
		foreach ($inputData as $key => $value)
		{
			$this->postData[$key] = $value;
			$postString .= $key . '=' . urlencode(stripslashes($value)) . '&';
		}
		$postString .= 'cmd=_notify-validate';
		$fp = fsockopen($host, '80', $errNum, $errStr, 30);
		if (!$fp)
		{
			$response = 'Could not open SSL connection to ' . $this->url;
			$this->logGatewayData($response);
			return false;
		}
		else
		{
			fputs($fp, "POST $path HTTP/1.1\r\n");
			fputs($fp, "Host: $host\r\n");
			fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
			fputs($fp, "Content-length: " . strlen($postString) . "\r\n");
			fputs($fp, "Connection: close\r\n\r\n");
			fputs($fp, $postString . "\r\n\r\n");
			while (!feof($fp))
			{
				$response .= fgets($fp, 1024);
			}
			fclose($fp);
			$this->logGatewayData($response);
		}
		if ($this->mode == 'sandbox' ||  (stristr($response, "VERIFIED") && ($inputData['payment_status'] == 'Completed')))
		{
			return true;
		}
		return false;
		
	} // END FUNCTION validate

	private function parseString($dataString)
	{
		parse_str($dataString, $dataArray);
		return $dataArray;
	}
	/***********************************************************************************************
	 * Verify payment
	 */
	public function verifyPayment()
	{
		$jinput = JFactory::getApplication()->input;
		if ($jinput->get('ep') == 'execute')
		{
			$this->executePayment();
		}
		else
		{
			$currency = new EshopCurrency();
			$inputData = $this->parseString(file_get_contents('php://input'));
			if ($this->validate($inputData)/* ->getVerificationStatus() */)
			{
				$row = JTable::getInstance('Eshop', 'Order');
				$id = $inputData['custom'];
				$amount = $inputData['mc_gross'];
				if ($amount < 0)
					return false;
				$row->load($id);
				if ($row->order_status_id == EshopHelper::getConfigValue('complete_status_id'))
					return false;
				$availableCurrenciesArr = array('AUD', 'CAD', 'EUR', 'GBP', 'JPY', 'USD', 'NZD', 'CHF', 'HKD', 'SGD', 'SEK', 'DKK', 'PLN', 'NOK', 'HUF', 'CZK', 'ILS', 'MXN', 'BRL', 'MYR', 'PHP', 'TWD', 'THB', 'TRY', 'RUB');
				if (!in_array($row->currency_code, $availableCurrenciesArr))
				{
					$total = $currency->convert($row->total, EshopHelper::getConfigValue('default_currency_code'), 'USD');
				}
				else 
				{
					$total = round($row->total * $row->currency_exchanged_value, 2);
				}
				if (abs($total - $amount) > 1)
					return false;
				$row->transaction_id = $inputData['txn_id'];
				$row->order_status_id = EshopHelper::getConfigValue('complete_status_id');
				$row->store();
				EshopHelper::completeOrder($row);
				JPluginHelper::importPlugin('eshop');
				$dispatcher = JDispatcher::getInstance();
				$dispatcher->trigger('onAfterCompleteOrder', array($row));
				//Send confirmation email here
				if (EshopHelper::getConfigValue('order_alert_mail'))
				{
					EshopHelper::sendEmails($row);
				}
				return true;
			}
			else // FALSE
			{
				return false;
			}
		}
	} // END FUNCTION verifyPayment
} // END CLASS