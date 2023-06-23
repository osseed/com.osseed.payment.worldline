<?php

require __DIR__ . '/../../../vendor/autoload.php';

use \OnlinePayments\Sdk\DefaultConnection;
use \OnlinePayments\Sdk\CommunicatorConfiguration;
use \OnlinePayments\Sdk\Communicator;
use \OnlinePayments\Sdk\Client;
use \OnlinePayments\Sdk\ProxyConfiguration;
use \OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use \OnlinePayments\Sdk\Domain\AmountOfMoney;
use \OnlinePayments\Sdk\Domain\Order;

class CRM_Core_Payment_Worldline extends CRM_Core_Payment {
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton;

  /**
   * Payment Processor Mode
   *   either test or live
   * @var string
   */
  protected $_mode;

  /**
   * Constructor.
   *
   * @param string $mode the mode of operation: live or test
   * @param array $paymentProcessor
   */
  public function __construct(string $mode, array $paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton( &$paymentProcessor, $mode = 'test', &$paymentForm = NULL, $force = FALSE ) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null ) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_Worldline( $mode, $paymentProcessor );
    }
    return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig( ) {
    $config = CRM_Core_Config::singleton();
    $error = [];

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Merchant ID" is not set in the Administer CiviCRM Payment Processor.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

    /**
   * Make a payment by interacting with an external payment processor.
   *
   * @param array|PropertyBag $params
   *   This may be passed in as an array or a \Civi\Payment\PropertyBag
   *   It holds all the values that have been collected to make the payment (eg. amount, address, currency, email).
   *
   * @param string $component
   *   Component is either 'contribution' or 'event' and is primarily used to determine the url
   *   to return the browser to. (Membership purchases come through as 'contribution'.)
   *
   * @return array
   *   Result array:
   *   - MUST contain payment_status (Completed|Pending)
   *   - MUST contain payment_status_id
   *   - MAY contain trxn_id
   *   - MAY contain fee_amount
   *   See: https://lab.civicrm.org/dev/financial/-/issues/141
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment( &$params, $component = 'contribute') {
    // Check for valid component.
    $component = strtolower($component);
    $config = CRM_Core_Config::singleton();
    if ($component != 'contribute' && $component != 'event') {
      CRM_Core_Error::fatal(ts('Component is invalid'));
    }

    /* @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = \Civi\Payment\PropertyBag::cast($params);

    if ($propertyBag->getAmount() == 0) {
      $result['payment_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $result['payment_status'] = 'Completed';
      return $result;
    }

    $params["membershipID"] = !empty($params["membershipID"])?$params["membershipID"]:'';
    $response_url = $config->userFrameworkBaseURL . 'civicrm/payment/ipn?processor_name=worldline&mode=' . $this->_mode . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&pid=' . $params["participantID"];

    // Your PSPID in either our test or live environment
    $merchantId = $this->_paymentProcessor['user_name'];

    // Put the value of the API Key which you can find in the Merchant Portal
    // https://secure.ogone.com/Ncol/Test/Backoffice/login/
    $apiKey = $this->_paymentProcessor['user_name'];

    // Put the value of the API Secret which you can find in the Merchant Portal
    // https://secure.ogone.com/Ncol/Prod/BackOffice/login/
    $apiSecret = $this->_paymentProcessor['signature'];

    // This endpoint is pointing to the TEST server
    // Note: Use the endpoint without the /v2/ part here
    $apiEndpoint = $this->_paymentProcessor['url_site'] ;

    $integrator = $config->userFrameworkBaseURL;

    $proxyConfiguration = null;

    // To use proxy, you should uncomment the section below
    // and replace proper settings with your settings of the proxy.
    // (additionally, you can comment on the previous setting).

    // $proxyConfiguration = new ProxyConfiguration(
    //    'proxyHost',
    //    'proxyPort',
    //    'proxyUserName',
    //    'proxyPassword'
    // );

    $communicatorConfiguration = new CommunicatorConfiguration(
        $apiKey,
        $apiSecret,
        $apiEndpoint,
        $integrator,
        $proxyConfiguration
    );

    $connection = new DefaultConnection();
    $communicator = new Communicator($connection, $communicatorConfiguration);

    $client = new Client($communicator);

    $merchantClient = $client->merchant($merchantId);

    /*
    * The HostedCheckoutClient object based on the MerchantClient
    * object created during initialisation
    */
    $hostedCheckoutClient = $merchantClient->hostedCheckout();

    $createHostedCheckoutRequest = new CreateHostedCheckoutRequest();
    $order = new Order();

    // Object of the AmountOfMoney
    $amountOfMoney = new AmountOfMoney();
    $amountOfMoney->setCurrencyCode($propertyBag->getCurrency());
    $amountOfMoney->setAmount($propertyBag->getAmount());
    $order->setAmountOfMoney($amountOfMoney);

    $createHostedCheckoutRequest->setOrder($order);

    // Get the response for the HostedCheckoutClient
    $createHostedCheckoutResponse = $hostedCheckoutClient->createHostedCheckout(
      $createHostedCheckoutRequest
    );

    // Prepare whatever data the 3rd party processor requires to take a payment.
    // The contents of the array below are just examples of typical things that
    // might be used.
    // TODO: Change params as per needed.
    $processorFormattedParams = [
      'merchantId' => $this->_paymentProcessor['user_name'],
      'amount' => $propertyBag->getAmount(),
      // Either use `getter` or `has` or use `$params['contributionID']` for any
      // values that might be null as the PropertyBag is very strict
      'order_id' => $propertyBag->getter('contributionID', TRUE, ''),
      // getNotifyUrl helps you construct the url to tell an off-site
      // processor where to send payment notifications (IPNs/webhooks) to.
      // Not all 3rd party processors need this.
      'notifyUrl' => $response_url, // $this->getNotifyUrl(), // TODO: define function if needed.
      // etc. depending on the features and requirements of the 3rd party API.
    ];
    if ($propertyBag->has('description')) {
      $processorFormattedParams['description'] = $propertyBag->getDescription();
    }

    // Allow further manipulation of the arguments via custom hooks
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $propertyBag, $processorFormattedParams);

    // At this point you need to interact with the payment processor.
    $result = callThe3rdPartyAPI($processorFormattedParams); // TODO: Do interaction with WorldLine APIs.

    // Some processors require that you send the user off-site to complete the payment.
    // This can be done with CRM_Utils_System::redirect(), but note that in this case
    // the script execution ends before we have returned anything. Therefore the payment
    // processes must be picked up asynchronously (e.g. webhook/IPN or some other return
    // process). You may need to store data on the session in some cases to accommodate.

    // If you are interacting with the processor server side & get a result then
    // you should either throw an exception or return a result array, depending on
    // the outcome.
    if ($result['failed']) {
      throw new \Civi\Payment\Exception\PaymentProcessorException($failureMessage);
    }

    // return [
    //   'payment_status'    => 'Completed',
    //   'payment_status_id' => CRM_Core_PseudoConstant::getKey(
    //                           'CRM_Contribute_BAO_Contribution',
    //                           'contribution_status_id',
    //                           'Completed'),
    //   'trxn_id'           => $result['payment_id'],
    //   'fee_amount'        => $result['fee'],
    // ];

    // TODO: Check what should we return?
    return $returnData;
  }

  // function doDirectPayment(&$params) {
  //   CRM_Core_Error::fatal(ts('This function is not implemented'));
  // }

  /**
   * Sets appropriate parameters for checking out to worldline Payment
   *
   * @param array $params  name value pair of contribution data
   *
   * @return void
   * @access public
   *
   */
  // function doTransferCheckout( &$params, $component = 'contribute' ) {
  //   // Build our query string;
  //   $query_string = '';

  //   $component = strtolower($component);
  //   $config = CRM_Core_Config::singleton();
  //   if ($component != 'contribute' && $component != 'event') {
  //     CRM_Core_Error::fatal(ts('Component is invalid'));
  //   }

  //   $currency_code = [
  //     'EUR' => '978',
  //     'USD' => '840',
  //     'CHF' => '756',
  //     'GBP' => '826',
  //     'CAD' => '124',
  //     'JPY' => '392',
  //     'MXP' => '484',
  //     'TRL' => '792',
  //     'AUD' => '036',
  //     'NZD' => '554',
  //     'NOK' => '578',
  //     'BRC' => '986',
  //     'ARP' => '032',
  //     'KHR' => '116',
  //     'TWD' => '901',
  //     'SEK' => '752',
  //     'DKK' => '208',
  //     'KRW' => '410',
  //     'SGD' => '702',
  //   ];
  //   $params["membershipID"] = !empty($params["membershipID"])?$params["membershipID"]:'';
  //   $response_url = $config->userFrameworkBaseURL . 'civicrm/payment/ipn?processor_name=worldline&mode=' . $this->_mode . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&pid=' . $params["participantID"];
  //   //Build the atos payment parameters.
  //   $transactionOrigin = $params["eventID"] .'-' . $params["contributionID"] . '-' . $params["contributionTypeID"] .'-' . $params["membershipID"];
  //   $trsansaction_refernence =
  //   $atos_data_params = [
  //     'merchantId' => $this->_paymentProcessor['user_name'],
  //     'keyVersion' => 1,
  //     'normalReturnUrl' => $response_url,
  //     'automaticResponseUrl' => $response_url,
  //     'customerId' => $params['contactID'],
  //     'customerIpAddress' => ip_address(),
  //     'customerLanguage' => 'en',
  //     'orderId' => $params['invoiceID'],
  //     'captureDay' => 0,
  //     'captureMode' => 'AUTHOR_CAPTURE',
  //     'transactionReference' => mt_rand(100000, 999999) . $params["participantID"],
  //     'transactionOrigin' => $transactionOrigin,
  //     'amount' => $params['amount'] * 100,
  //     'currencyCode' => $currency_code[$params['currencyID']],
  //   ];
  //   $attached_data = [];
  //   // Allow further manipulation of the arguments via custom hooks ..
  //   CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $worldline_payment_params);

  //   // Converts the array into a string of key=value.
  //   foreach ($atos_data_params as $key => $value) {
  //     $attached_data[] = "$key=$value";
  //   }
  //   $atos_params_string = implode('|', $attached_data);
  //   $atos_params_string_data = base64_encode($atos_params_string);
  //   $atosParams = [
  //     'Data' => $atos_params_string_data,
  //     'Seal' => self::worldline_atos_generate_data_seal($atos_params_string_data, $this->_paymentProcessor['signature']),
  //     'Encode' => 'base64',
  //     'InterfaceVersion' => 'HP_2.3',
  //   ];

  //   // Do a post request with required params
  //   require_once 'HTTP/Request.php';
  //   $post_params = [
  //     'method' => HTTP_REQUEST_METHOD_POST,
  //     'allowRedirects' => TRUE,
  //   ];
  //   $payment_site_url = $this->_paymentProcessor['url_site'];
  //   $request = new HTTP_Request($payment_site_url, $post_params);
  //   foreach ($atosParams as $key => $value) {
  //     $request->addPostData($key, $value);
  //   }
  //   $result = $request->sendRequest();

  //   if (PEAR::isError($result)) {
  //     CRM_Core_Error::fatal($result->getMessage());
  //   }

  //   if ($request->getResponseCode() != 200) {
  //     CRM_Core_Error::fatal(ts('Invalid response code received from Worldline Checkout: %1',
  //         [1 => $request->getResponseCode()]
  //       ));
  //   }
  //   echo $request->getResponseBody();
  //   CRM_Utils_System::civiExit();
  // }

  /**
   * Returns a hashed value of data.
   *
   * This is used to generate a seal for all requests to ATOS payment servers.
   *
   * @param string $data
   *   Data to convert.
   * @param array $secret_key
   *   The secret key of atos worldine account.
   *
   * @return string
   *   return the hashed value.
   */
  // static function worldline_atos_generate_data_seal($data, $secret_key) {
  //   $data_string = $data . $secret_key;
  //   return hash('sha256', trim($data_string));
  // }

  // static function formatAmount($amount, $size, $pad = 0){
  //   $amount_str = preg_replace('/[\.,]/', '', strval($amount));
  //   $amount_str = str_pad($amount_str, $size, $pad, STR_PAD_LEFT);
  //   return $amount_str;
  // }

  // static function trimAmount($amount, $pad = '0'){
  //   return ltrim(trim($amount), $pad);
  // }

  /**
   * Process incoming notification.
   */
  // static public function handlePaymentNotification() {
  //   // Change this to fit your processor name.
  //   require_once 'worldlineIPN.php';
  //   // Change this to match your payment processor class.
  //   CRM_Core_Payment_Worldline_worldlineIPN::main();
  // }

}
