<?php 
/*EWS Test*/

class api_v3_EwsTest extends CiviUnitTestCase {

  /**
   * Assume empty database with just civicrm_data.
   */
  protected $_individualId;
  protected $_contribution;
  protected $_financialTypeId = 2;
  protected $_apiversion;
  protected $_entity = 'Contribution';
  public $debug = 0;
  protected $_params;
  protected $_ids = array();
  protected $_pageParams = array();
  protected $_debugInfo = 1;
  /**
   * Payment processor ID (dummy processor).
   *
   * @var int
   */
  protected $paymentProcessorID;

  /**
   * Parameters to create payment processor.
   *
   * @var array
   */
  protected $_processorParams = array();

  /**
   * ID of created event.
   *
   * @var int
   */
  protected $_eventID;

  /**
   * @var CiviMailUtils
   */
  protected $mut;

  /**
   * Setup function.
   */
  public function setUp() {
    parent::setUp();

    $this->_apiversion = 3;

    $this->_individualId = $this->individualCreate();

    $this->_params = array(
      'contact_id' => $this->_individualId,
      'receive_date' => '20120511',
      'total_amount' => 100.00,
      'financial_type_id' => $this->_financialTypeId,
      'non_deductible_amount' => 10.00,
      'fee_amount' => 5.00,
      'net_amount' => 95.00,
      'source' => 'SSF',
      'contribution_status_id' => 1,
    );
    $this->_processorParams = array(
      'domain_id' => 1,
      'name' => 'Dummy',
      'payment_processor_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Financial_BAO_PaymentProcessor', 'payment_processor_type_id', 'Dummy'),
      'financial_account_id' => 12,
      'is_active' => 1,
      'user_name' => '',
      'url_site' => 'http://dummy.com',
      'url_recur' => 'http://dummy.com',
      'billing_mode' => 1,
    );
    $this->paymentProcessorID = $this->processorCreate();
    $this->_pageParams = array(
      'title' => 'Test Contribution Page',
      'financial_type_id' => 1,
      'currency' => 'USD',
      'financial_account_id' => 1,
      'payment_processor' => $this->paymentProcessorID,
      'is_active' => 1,
      'is_allow_other_amount' => 1,
      'min_amount' => 10,
      'max_amount' => 1000,
    );
    
  }

  /**
   * Clean up after each test.
   */
  public function tearDown() {
    $this->quickCleanUpFinancialEntities();
    $this->quickCleanup(array('civicrm_uf_match'));
    $financialAccounts = $this->callAPISuccess('FinancialAccount', 'get', array());
    foreach ($financialAccounts['values'] as $financialAccount) {
      if ($financialAccount['name'] == 'Test Tax financial account ' || $financialAccount['name'] == 'Test taxable financial Type') {
        $entityFinancialTypes = $this->callAPISuccess('EntityFinancialAccount', 'get', array(
          'financial_account_id' => $financialAccount['id'],
        ));
        foreach ($entityFinancialTypes['values'] as $entityFinancialType) {
          $this->callAPISuccess('EntityFinancialAccount', 'delete', array('id' => $entityFinancialType['id']));
        }
        $this->callAPISuccess('FinancialAccount', 'delete', array('id' => $financialAccount['id']));
      }
    }
  }


   /**
   * CRM-17718 test appropriate action if financial type has changed for single line items.
   */
  public function testRepeatTransactionUpdatedFinancialType() {
  	
    $originalContribution = $this->setUpRecurringContribution(array(), array('financial_type_id' => 2));
    /*
    $result = civicrm_api3('Contribution', 'create', array(
      'id' => 1,
      'financial_type_id' => 3,
    ));
    $EWSUpdateContribution = civicrm_api3('line_item', 'create', array('id' => 1, 'financial_type_id' => 1));
    */
  
    //TODO Explore all the instructions after this "callAPISuccess" Call and get all the LineItems before and after this one.
    // Here its created the 2nd contribution?
    $this->callAPISuccess('contribution', 'repeattransaction', array(
      'contribution_recur_id' => $originalContribution['id'],
      'contribution_status_id' => 'Completed',
      'trxn_id' => uniqid(),
    ));

    $lineItemParams = array(
      'entity_id' => $originalContribution['id'],
      'sequential' => 1,
      'return' => array(
        'entity_table',
        'qty',
        'unit_price',
        'line_total',
        'label',
        'financial_type_id',
        'deductible_amount',
        'price_field_value_id',
        'price_field_id',
      ),
    );

    $this->callAPISuccessGetSingle('contribution', array(
      'total_amount' => 100,
      'financial_type_id' => 1,
    ));

    $lineItem1 = $this->callAPISuccess('line_item', 'get', array_merge($lineItemParams, array(
      'entity_id' => $originalContribution['id'],
    )));

    $expectedLineItem = array_merge(
      $lineItem1['values'][0], array(
        'line_total' => '100.00',
        'unit_price' => '100.00',
        'financial_type_id' => 1,
        'contribution_type_id' => 1,
      )
    );

    $lineItem2 = $this->callAPISuccess('line_item', 'get', array_merge($lineItemParams, array(
      'entity_id' => $originalContribution['id'] + 1,
    )));


    $contributionsHere = $this->callAPISuccess('contribution', 'get', array('sequential' => 1,));

    echo "\n" . '--- Contributions Y:' . "\n";
    //echo var_dump($contributionsHere['values']);
    foreach($contributionsHere['values'] as $key){
    	echo "\n" . 'contribution_id: ' . $key['contribution_id'] . "\n";
    	echo 'financial_type_id: ' . $key['financial_type_id'] . "\n";
    	//echo 'contribution_type_id: ' . $key['contribution_type_id'] . "\n";
    } unset($key);

    $lineItemsHere = $this->callAPISuccess('line_item', 'get', array('sequential' => 1,));

    echo "\n" . '--- LineItems Y: ' . "\n";

    foreach ($lineItemsHere['values'] as $key) {
    	echo "\n" . 'Id: ' . $key['id'] . "\n";
    	//echo 'contribution_id: ' . $key['contribution_id'] . "\n";
    	echo 'financial_type_id: ' . $key['financial_type_id'] . "\n";
    } unset($key);

    unset($expectedLineItem['id'], $expectedLineItem['entity_id']);
    unset($lineItem2['values'][0]['id'], $lineItem2['values'][0]['entity_id']);
    $this->assertEquals($expectedLineItem, $lineItem2['values'][0]);
  }



   /**
   * Set up the basic recurring contribution for tests.
   *
   * @param array $generalParams
   *   Parameters that can be merged into the recurring AND the contribution.
   *
   * @param array $recurParams
   *   Parameters to merge into the recur only.
   *
   * @return array|int
   */
  protected function setUpRecurringContribution($generalParams = array(), $recurParams = array()) {
    $contributionRecur = $this->callAPISuccess('contribution_recur', 'create', array_merge(array(
      'contact_id' => $this->_individualId,
      'installments' => '12',
      'frequency_interval' => '1',
      'amount' => '100',
      'contribution_status_id' => 1,
      'start_date' => '2012-01-01 00:00:00',
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'payment_processor_id' => $this->paymentProcessorID,
    ), $generalParams, $recurParams));
    /**
     * Checking the contribution_recur
     */
    //$EWSUpdateLineItem = civicrm_api3('line_item', 'create', array('id' => 1, 'financial_type_id' => 3));

    //LineItem its empty at this moment.
    //Contribution its empty at this moment.


    $EWSUpdateContributionRecur = civicrm_api3('contribution_recur', 'create', array('id' => 1, 'financial_type_id' => 1));

	$contributionRecurHere = $this->callAPISuccess('contribution_recur', 'get', array('sequential' => 1,));
	echo "\n" . '--- Contribution Recur X' . "\n";
	foreach($contributionRecurHere['values'] as $key){
		echo "\n" . "financial_type_id: " . $key['financial_type_id'] . "\n";
	}

	
    $originalContribution = $this->callAPISuccess('contribution', 'create', array_merge(
      $this->_params,
      array(
        'contribution_recur_id' => $contributionRecur['id'],
      ), $generalParams)
    );

    $EWSUpdateContribution = civicrm_api3('contribution', 'create', array('id' => 1, 'financial_type_id' => 3));
    $EWSUpdateLineItem = civicrm_api3('line_item', 'create', array('id' => 1, 'financial_type_id' => 2));
    //$EWSUpdateContributionRecur = civicrm_api3('contribution_recur', 'create', array('id' => 1, 'financial_type_id' => 1));

    $contributionsHere = $this->callAPISuccess('contribution', 'get', array('sequential' => 1,));

    echo "\n" . '--- Contributions X2:' . "\n";

    foreach($contributionsHere['values'] as $key){
    	echo "\n" . 'contribution_id: ' . $key['contribution_id'] . "\n";
    	echo 'financial_type_id: ' . $key['financial_type_id'] . "\n";
    	//echo 'contribution_type_id: ' . $key['contribution_type_id'] . "\n";
    } unset($key);

    //$EWSUpdateLineItem = civicrm_api3('line_item', 'create', array('id' => 1, 'financial_type_id' => 3));

    $lineItemsHere = $this->callAPISuccess('line_item', 'get', array('sequential' => 1,));

    echo "\n" . '--- LineItems X2: ' . "\n";

    foreach ($lineItemsHere['values'] as $key) {
    	echo "\n" . 'Id: ' . $key['id'] . "\n";
    	//echo 'contribution_id: ' . $key['contribution_id'] . "\n";
    	echo 'financial_type_id: ' . $key['financial_type_id'] . "\n";
    } unset($key);

    return $originalContribution;
  }


}

