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

    $component = strtolower($component);
    $config = CRM_Core_Config::singleton();
    if ($component != 'contribute' && $component != 'event') {
      CRM_Core_Error::fatal(ts('Component is invalid'));
    }

    if ($component == "event") {
      $returnURL = CRM_Utils_System::url('civicrm/event/register',
        "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
        TRUE, NULL, FALSE
      );
    }
    elseif ($component == "contribute") {
      $returnURL = CRM_Utils_System::url('civicrm/contribute/transact',
        "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
        TRUE, NULL, FALSE
      );
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

    $atos_data_params = array(
      'merchantId' => $this->_paymentProcessor['user_name'],
      'keyVersion' => 1,
      'normalReturnUrl' => $returnURL,
      'automaticResponseUrl' => $returnURL,
      'customerId' => $params['contactID'],
      'customerIpAddress' => ip_address(),
      'customerLanguage' => 'en',
      'orderId' => $params['invoiceID'],
      'transactionOrigin' => 'CIVICRMPAYMENT',
      'captureDay' => 0,
      'captureMode' => 'AUTHOR_CAPTURE',
      'transactionReference' => $params['invoiceID'],
      'amount' => $params['amount'],
      'currencyCode' => $currency_code[$params['currencyID']],
    );
    $attached_data = array();
    // Converts the array into a string of key=value.
    foreach ($atos_data_params as $key => $value) {
      $attached_data[] = "$key=$value";
    }
    $atos_params_string = implode('|', $attached_data);
    $atos_params_string_data = base64_encode($atos_params_string);
    $atosParams = array(
      'Data' => $atos_params_string_data,
      'Seal' => worldline_atos_generate_data_seal($atos_params_string_data, $this->_paymentProcessor['signature']),
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
    CRM_Utils_System::redirect($request->getUrl());
    CRM_Utils_System::civiExit();
  }

  protected function isValidResponse($params){
    return true;
  }

  public function handlePaymentNotification() {
    $responses = array(
      '00' => 'Transaction success, authorization accepted.',
      '02' => 'Please phone the bank because the authorization limit on the card has been exceeded',
      '03' => 'Invalid merchant contract',
      '05' => 'Do not honor, authorization refused',
      '12' => 'Invalid transaction, check the parameters sent in the request.',
      '14' => 'Invalid card number or invalid Card Security Code or Card (for MasterCard) or invalid Card Verification Value (for Visa)',
      '17' => 'Cancellation of payment by the end user',
      '24' => 'Invalid status.',
      '25' => 'Transaction not found in database',
      '30' => 'Invalid format',
      '34' => 'Fraud suspicion',
      '40' => 'Operation not allowed to this merchant',
      '60' => 'Pending transaction',
      '63' => 'Security breach detected, transaction stopped.',
      '75' => 'The number of attempts to enter the card number has been exceeded (Three tries exhausted)',
      '90' => 'Acquirer server temporarily unavailable',
      '94' => 'Duplicate transaction. (transaction reference already reserved)',
      '97' => 'Request time-out; transaction refused',
      '99' => 'Payment page temporarily unavailable',
    );
    $module = self::retrieve('md', 'String', 'GET', false);
    $qfKey = self::retrieve('qfKey', 'String', 'GET', false);
    $response = array();

    $response = worldline_atos_parse_response($_POST['Data']);
    $transaction_id = explode('T', $response['transactionReference']);
    dsm($response);

    if($this->isValidResponse($response)){
      switch ($module) {
        case 'contribute':
          if ($transaction_id) {
            $query = "UPDATE civicrm_contribution SET trxn_id='" .$transaction_id . "', contribution_status_id=1 where id='" . self::trimAmount($response['Ds_Order']) . "'";
            CRM_Core_DAO::executeQuery($query);
          }
          break;
        case 'event':
          if ($transaction_id) {
            $query = "UPDATE civicrm_contribution SET trxn_id='" . $transaction_id . "', contribution_status_id=1 where id='" . self::trimAmount($response['Ds_Order']) . "'";
            CRM_Core_DAO::executeQuery($query);
          }
          break;
        default:
          require_once 'CRM/Core/Error.php';
          CRM_Core_Error::debug_log_message("Could not get module name from request url");
      }
    }
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
  $secret_key = trim($secret_key);
  return hash('sha256', $data . $secret_key);
}

/**
 * Converts an encoded response string into an array of data.
 *
 * @param string $data
 *   A string to decode and to convert into an array.
 *
 * @return array|bool
 *   Return FALSE if the response data wasn't valid.
 */
function worldline_atos_parse_response($data) {
  if (empty($data)) {
    return FALSE;
  }
  // Decode encoded data (base64URL)
  $data = base64_decode(strtr($data, '-_,', '+/='));
  $data = explode('|', $data);
  foreach ($data as $value) {
    list($key, $param) = explode('=', $value);
    $response[$key] = (string) $param;
  }

  return $response;
}
