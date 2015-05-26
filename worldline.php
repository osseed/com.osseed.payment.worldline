<?php
 
require_once 'CRM/Core/Payment.php';
 
class osseed_payment_worldline extends CRM_Core_Payment {
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;
 
  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = null;
 
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
    $this->_processorName    = ts('Worldline atos');
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
  static function &singleton( $mode, &$paymentProcessor ) {
      $processorName = $paymentProcessor['name'];
      if (self::$_singleton[$processorName] === null ) {
          self::$_singleton[$processorName] = new osseed_payment_worldline( $mode, $paymentProcessor );
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
    //CRM_Core_Error::fatal(ts('This function is not implemented'));
  }
 
  /**
   * Sets appropriate parameters for checking out to Worldline payment
   * @param array $params  name value pair of contribution datat
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout( &$params, $component ) {
    // Build our query string;
    $query_string = '';
    $atos_data_params = array(
      'merchantId' => $this->_paymentProcessor['url_site'],
      'keyVersion' => 1,
      'normalReturnUrl' => '',
      'automaticResponseUrl' => '',
      'customerId' => $params['contactID'],
      'customerIpAddress' => ip_address(),
      'orderId' => $params['invoiceID'],
      'transactionOrigin' => 'CIVICRMPAYMENT',
      'captureDay' => 0,
      'captureMode' => 'AUTHOR_CAPTURE',
      'transactionReference' => $params['invoiceID'],
      'amount' => $params['amount'],
      'currencyCode' => $params['currencyID'],
    );
    $attached_data = array();
    // Converts the array into a string of key=value.
    foreach ($atos_data_params as $key => $value) {
      $attached_data[] = "$key=$value";
    }
    $atos_params_string = implode('|', $attached_data);

    $atosParams = array(
      'Data' => base64_encode($atos_params_string),
      'Seal' => worldline_atos_generate_data_seal($atos_params, $this->_paymentProcessor['signature']),
      'Encode' => 'base64',
      'InterfaceVersion' => 'HP_2.3',
    );

    require_once 'HTTP/Request.php';
    $post_params = array(
      'method' => HTTP_REQUEST_METHOD_POST,
      'allowRedirects' => TRUE,
    );

    // Atos payment url
    $payment_site_url = $this->_paymentProcessor['url_site'];
    $request = new HTTP_Request($payment_site_url, $post_params);
    foreach ($atosParams as $key => $value) {
      $request->addPostData($key, $value);
    }
    $result = $request->sendRequest();
    if (PEAR::isError($result)) {
      CRM_Core_Error::fatal($result->getMessage());
    }
    //CRM_Utils_System::redirect($request->getUrl());
    CRM_Utils_System::civiExit();
  }
}

/**
 * Returns a hashed value of data.
 *
 * This is used to generate a seal for all requests to ATOS payment servers.
 *
 * @param string $data
 *   Data to convert.
 * @param array $secret_key
 *   The secret key of atos wordline account.
 *
 * @return string
 *   return the hashed value.
 */
function worldline_atos_generate_data_seal($data, $secret_key) {
  return hash('sha256', $data . $secret_key);
}