<?php

require_once 'CRM/Core/Payment.php';

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
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  //static protected $_mode;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct( $mode, &$paymentProcessor ) {
    $this->_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('worldline Payment');
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

    $error = array();

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

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Sets appropriate parameters for checking out to worldline Payment
   *
   * @param array $params  name value pair of contribution data
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout( &$params, $component = 'contribute' ) {
    // Build our query string;
    $query_string = '';

    $component = strtolower($component);
    $config = CRM_Core_Config::singleton();
    if ($component != 'contribute' && $component != 'event') {
      CRM_Core_Error::fatal(ts('Component is invalid'));
    }

    $currency_code = array(
      'EUR' => '978',
      'USD' => '840',
      'CHF' => '756',
      'GBP' => '826',
      'CAD' => '124',
      'JPY' => '392',
      'MXP' => '484',
      'TRL' => '792',
      'AUD' => '036',
      'NZD' => '554',
      'NOK' => '578',
      'BRC' => '986',
      'ARP' => '032',
      'KHR' => '116',
      'TWD' => '901',
      'SEK' => '752',
      'DKK' => '208',
      'KRW' => '410',
      'SGD' => '702',
    );
    $params["membershipID"] = !empty($params["membershipID"])?$params["membershipID"]:'';
    $response_url = $config->userFrameworkBaseURL . 'civicrm/payment/ipn?processor_name=worldline&mode=' . $this->_mode . '&md=' . $component . '&qfKey=' . $params["qfKey"] . '&pid=' . $params["participantID"];
    //Build the atos payment parameters.
    $transactionOrigin = $params["eventID"] .'-' . $params["contributionID"] . '-' . $params["contributionTypeID"] .'-' . $params["membershipID"];
    $trsansaction_refernence =
    $atos_data_params = array(
      'merchantId' => $this->_paymentProcessor['user_name'],
      'keyVersion' => 1,
      'normalReturnUrl' => $response_url,
      'automaticResponseUrl' => $response_url,
      'customerId' => $params['contactID'],
      'customerIpAddress' => ip_address(),
      'customerLanguage' => 'en',
      'orderId' => $params['invoiceID'],
      'captureDay' => 0,
      'captureMode' => 'AUTHOR_CAPTURE',
      'transactionReference' => mt_rand(100000, 999999) . $params["participantID"],
      'transactionOrigin' => $transactionOrigin,
      'amount' => $params['amount'] * 100,
      'currencyCode' => $currency_code[$params['currencyID']],
    );
    $attached_data = array();
    // Allow further manipulation of the arguments via custom hooks ..
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $worldline_payment_params);

    // Converts the array into a string of key=value.
    foreach ($atos_data_params as $key => $value) {
      $attached_data[] = "$key=$value";
    }
    $atos_params_string = implode('|', $attached_data);
    $atos_params_string_data = base64_encode($atos_params_string);
    $atosParams = array(
      'Data' => $atos_params_string_data,
      'Seal' => self::worldline_atos_generate_data_seal($atos_params_string_data, $this->_paymentProcessor['signature']),
      'Encode' => 'base64',
      'InterfaceVersion' => 'HP_2.3',
    );

    // Do a post request with required params
    require_once 'HTTP/Request.php';
    $post_params = array(
      'method' => HTTP_REQUEST_METHOD_POST,
      'allowRedirects' => TRUE,
    );
    $payment_site_url = $this->_paymentProcessor['url_site'];
    $request = new HTTP_Request($payment_site_url, $post_params);
    foreach ($atosParams as $key => $value) {
      $request->addPostData($key, $value);
    }
    $result = $request->sendRequest();

    if (PEAR::isError($result)) {
      CRM_Core_Error::fatal($result->getMessage());
    }

    if ($request->getResponseCode() != 200) {
      CRM_Core_Error::fatal(ts('Invalid response code received from Worldline Checkout: %1',
          array(1 => $request->getResponseCode())
        ));
    }
    echo $request->getResponseBody();
    CRM_Utils_System::civiExit();
  }

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
  static function worldline_atos_generate_data_seal($data, $secret_key) {
    $data_string = $data . $secret_key;
    return hash('sha256', trim($data_string));
  }

  static function formatAmount($amount, $size, $pad = 0){
    $amount_str = preg_replace('/[\.,]/', '', strval($amount));
    $amount_str = str_pad($amount_str, $size, $pad, STR_PAD_LEFT);
    return $amount_str;
  }

  static function trimAmount($amount, $pad = '0'){
    return ltrim(trim($amount), $pad);
  }

  /**
   * Process incoming notification.
   */
  static public function handlePaymentNotification() {
    // Change this to fit your processor name.
    require_once 'worldlineIPN.php';
    // Change this to match your payment processor class.
    CRM_Core_Payment_Worldline_worldlineIPN::main();
  }

}
