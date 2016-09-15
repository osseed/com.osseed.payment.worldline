<?php

/*
 +--------------------------------------------------------------------+
 | Worldline IPN                                                    |
 +--------------------------------------------------------------------+
*/

/**
 * @package CRM
 */

require_once 'CRM/Core/Payment/BaseIPN.php';

class com_osseed_payment_worldline_worldlineIPN extends CRM_Core_Payment_BaseIPN {
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
 
    static function retrieve( $name, $type, $object, $abort = true ) {
      $value = CRM_Utils_Array::value($name, $object);
      if ($abort && $value === null) {
        CRM_Core_Error::debug_log_message("Could not find an entry for $name");
        echo "Failure: Missing Parameter<p>";
        exit();
      }
 
      if ($value) {
        if (!CRM_Utils_Type::validate($value, $type)) {
          CRM_Core_Error::debug_log_message("Could not find a valid entry for $name");
          echo "Failure: Invalid Parameter<p>";
          exit();
        }
      }
 
      return $value;
    }
 
 
    /**
     * Constructor
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return void
     */
    function __construct($mode, &$paymentProcessor) {
      parent::__construct();
 
      $this->_mode = $mode;
      $this->_paymentProcessor = $paymentProcessor;
    }
 
    /**
     * The function gets called when a new order takes place.
     *
     * @param xml   $dataRoot    response send by google in xml format
     * @param array $privateData contains the name value pair of <merchant-private-data>
     *
     * @return void
     *
     */
    function newOrderNotify( $success, $privateData, $component, $amount, $transactionReference ) {
        $ids = $input = $params = array( );
 
        $input['component'] = strtolower($component);
 
        $ids['contact']          = self::retrieve( 'contactID'     , 'Integer', $privateData, true );
        $ids['contribution']     = self::retrieve( 'contributionID', 'Integer', $privateData, true );
 
        if ( $input['component'] == "event" ) {
            $ids['event']       = self::retrieve( 'eventID'      , 'Integer', $privateData, true );
            $ids['participant'] = self::retrieve( 'participantID', 'Integer', $privateData, true );
            $ids['membership']  = null;
        } else {
            $ids['membership'] = self::retrieve( 'membershipID'  , 'Integer', $privateData, false );
        }
        $ids['contributionRecur'] = $ids['contributionPage'] = null;
 
        if ( ! $this->validateData( $input, $ids, $objects ) ) {
            return false;
        }
 
        // make sure the invoice is valid and matches what we have in the contribution record
        $input['invoice']    =  $privateData['invoiceID'];
        $input['newInvoice'] =  $transactionReference;
        $contribution        =& $objects['contribution'];
        $input['trxn_id']  =    $transactionReference;
 
        if ( $contribution->invoice_id != $input['invoice'] ) {
            CRM_Core_Error::debug_log_message( "Invoice values dont match between database and IPN request" );
            echo "Failure: Invoice values dont match between database and IPN request<p>";
            return;
        }
 
        // lets replace invoice-id with Payment Processor -number because thats what is common and unique
        // in subsequent calls or notifications sent by google.
        $contribution->invoice_id = $input['newInvoice'];
 
        $input['amount'] = $amount;
 
        if ( $contribution->total_amount != $input['amount'] ) {
            CRM_Core_Error::debug_log_message( "Amount values dont match between database and IPN request" );
            echo "Failure: Amount values dont match between database and IPN request."
                        .$contribution->total_amount."/".$input['amount']."<p>";
            return;
        }
 
        require_once 'CRM/Core/Transaction.php';
        $transaction = new CRM_Core_Transaction( );
 
        // check if contribution is already completed, if so we ignore this ipn
 
        if ( $contribution->contribution_status_id == 1 ) {
            CRM_Core_Error::debug_log_message( "returning since contribution has already been handled" );
            echo "Success: Contribution has already been handled<p>";
            return true;
        } else {
            /* Since trxn_id hasn't got any use here,
             * lets make use of it by passing the eventID/membershipTypeID to next level.
             * And change trxn_id to the payment processor reference before finishing db update */
            if ( $ids['event'] ) {
                $contribution->trxn_id =
                    $ids['event']       . CRM_Core_DAO::VALUE_SEPARATOR .
                    $ids['participant'] ;
            } else {
                $contribution->trxn_id = $ids['membership'];
            }
        }
        $this->completeTransaction ( $input, $ids, $objects, $transaction);
        return true;
    }
 
 
    /**
     * singleton function used to manage this object
     *
     * @param string $mode the mode of operation: live or test
     *
     * @return object
     * @static
     */
    static function &singleton( $mode, $component, &$paymentProcessor ) {
        if ( self::$_singleton === null ) {
            self::$_singleton = new com_osseed_payment_worldline_worldlineIPN( $mode, $paymentProcessor );
        }
        return self::$_singleton;
    }
 
    /**
     * The function returns the component(Event/Contribute..)and whether it is Test or not
     *
     * @param array   $privateData    contains the name-value pairs of transaction related data
     *
     * @return array context of this call (test, component, payment processor id)
     * @static
     */
    static function getContext($privateData)    {
      require_once 'CRM/Contribute/DAO/Contribution.php';
 
      $component = null;
      $isTest = null;
 
      $contributionID = $privateData['contributionID'];
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionID;
 
      if (!$contribution->find(true)) {
        CRM_Core_Error::debug_log_message("Could not find contribution record: $contributionID");
        echo "Failure: Could not find contribution record for $contributionID<p>";
        exit();
      }
 
      if (stristr($contribution->source, 'Online Contribution')) {
        $component = 'contribute';
      }
      elseif (stristr($contribution->source, 'Online Event Registration')) {
        $component = 'event';
      }
      $isTest = $contribution->is_test;
 
      $duplicateTransaction = 0;
      if ($contribution->contribution_status_id == 1) {
        //contribution already handled. (some processors do two notifications so this could be valid)
        $duplicateTransaction = 1;
      }
 
      if ($component == 'contribute') {
        if (!$contribution->contribution_page_id) {
          CRM_Core_Error::debug_log_message("Could not find contribution page for contribution record: $contributionID");
          echo "Failure: Could not find contribution page for contribution record: $contributionID<p>";
          exit();
        }
 
        // get the payment processor id from contribution page
        $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', 
        $contribution->contribution_page, 'payment_processor');
      }
      else {
        $eventID = $privateData['eventID'];
 
        if (!$eventID) {
          CRM_Core_Error::debug_log_message("Could not find event ID");
          echo "Failure: Could not find eventID<p>";
          exit();
        }
 
        // we are in event mode
        // make sure event exists and is valid
        require_once 'CRM/Event/DAO/Event.php';
        $event = new CRM_Event_DAO_Event();
        $event->id = $eventID;
        if (!$event->find(true)) {
          CRM_Core_Error::debug_log_message("Could not find event: $eventID");
          echo "Failure: Could not find event: $eventID<p>";
          exit();
        }
 
        // get the payment processor id from contribution page
        $paymentProcessorID = $event->payment_processor;
      }
 
      if (!$paymentProcessorID) {
        CRM_Core_Error::debug_log_message("Could not find payment processor for contribution record: $contributionID");
        echo "Failure: Could not find payment processor for contribution record: $contributionID<p>";
        exit();
      }
 
      return array($isTest, $component, $paymentProcessorID, $duplicateTransaction);
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
    static function worldline_atos_parse_response($data) {
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
    
    static function isValidResponse($params) {
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

      $result = array();
      $result['responseCode'] = $params['responseCode'];
      $result['responseMessage'] = $responses[$result['responseCode']];
      // Check for the resposne status codes and pass the validation accordingly.
      if($params['responseCode'] == '00')) {
        $result['success'] = TRUE;
      } else {
        $result['success'] = FALSE;
      }
      return $result;
    }

    /**
     * This method is handles the response that will be invoked (from worldlineNotify.php) every time.
     */
    static function main() {
      $config = CRM_Core_Config::singleton();
      $module = $_GET['md'];
      $qfKey = $_GET['qfKey'];
      $response = array();
      $response = self::worldline_atos_parse_response($_POST['Data']);
      $response = array_merge($_GET, $response);
      $mode = $_GET['mode'];
      $transaction_id = $response['transactionReference'];
      $amount_charged = $response['amount'] / 100;
      $result = self::isValidResponse($response);
      $privateData = array();
      $transactionOrigin = explode('-', $response['transactionOrigin']);
      $privateData['invoiceID'] = $response['orderId'];
      $privateData['qfKey'] = $qfKey;
      $privateData['contactID'] = $response['customerId'];
      $privateData['eventID'] = (isset($transactionOrigin[0])) ? $transactionOrigin[0] : '';
      $privateData['contributionID'] = (isset($transactionOrigin[1])) ? $transactionOrigin[1] : '';
      $privateData['participantID'] = (isset($transactionOrigin[2])) ? $transactionOrigin[2] : '';
      $privateData['contributionTypeID'] = (isset($transactionOrigin[3])) ? $transactionOrigin[3] : '';
      $privateData['membershipID'] = (isset($transactionOrigin[4])) ? $transactionOrigin[4] : '';
      if($result['success']) {
        $success = TRUE;
        list($mode, $component, $paymentProcessorID, $duplicateTransaction) = self::getContext($privateData);
        $mode = $mode ? 'test' : 'live';
        require_once 'CRM/Financial/BAO/PaymentProcessor.php';
        $paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($paymentProcessorID, $mode);
        $ipn=& self::singleton( $mode, $component, $paymentProcessor );
        if ($duplicateTransaction == 0) {
          // Process the transaction.
           $ipn->newOrderNotify($success, $privateData, $component, 
           $amount_charged, $transaction_id);
        }

        // Redirect our users to the correct url.
        if ($module == "event") {
          $finalURL = CRM_Utils_System::url('civicrm/event/register', 
              "_qf_ThankYou_display=1&qfKey={$qfKey}", false, null, false);
        }
        elseif ($module == "contribute") {
          $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', 
              "_qf_ThankYou_display=1&qfKey={$qfKey}", false, null, false);
        }
      } else {
        CRM_Core_Session::setStatus($result['responseCode'], $result['responseMessage'], 'error');
        if ($module == "event") {
          // Mark the participant status as rejected.
          $participantId = $privateData['participantID'];
          $status = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', $participantId, 'status_id');
          $participantStatuses = CRM_Core_PseudoConstant::get('CRM_Event_DAO_Participant', 'status_id', array(
            'labelColumn' => 'name',
            'flip' => 1,
          ));
          if(isset($participantStatuses['Rejected'])) {
            $participant_rejected_status_id = $participantStatuses['Rejected'];
          } 
          elseif (isset($participantStatuses[' Cancelled'])) {
            $participant_rejected_status_id = $participantStatuses['Cancelled'];
          }
          CRM_Event_BAO_Participant::updateParticipantStatus($participantId, $status, $participant_rejected_status_id, TRUE);
          
          $finalURL = CRM_Utils_System::url('civicrm/event/register',
          "_qf_Register_display&cancel=1&qfKey={$qfKey}", false, null, false);
        } elseif ( $module == "contribute" ) {
          $finalURL = CRM_Utils_System::url('civicrm/contribute/transact', 
          "_qf_Main_display=1&cancel=1&qfKey={$qfKey}", false, null, false);
        }
      }

      CRM_Utils_System::redirect($finalURL);
    }
 
}
