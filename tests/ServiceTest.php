<?php

use SurfingCrab\AgnoPay\Service as AgnoPayService;
use SurfingCrab\AgnoPay\Reference\PdoSqliteDataLayer;

use Symfony\Component\HttpFoundation\Request as PsrRequest;

use SurfingCrab\AgnoPay\DataModels\RequestModel;
use SurfingCrab\AgnoPay\DataModels\StateModel;
use SurfingCrab\AgnoPay\DataModels\ResultModel;
use SurfingCrab\AgnoPay\DataModels\InputModel;
use SurfingCrab\AgnoPay\DataModels\RedirectInputModel;

use SurfingCrab\AgnoPay\Mock\Bml\Client as BmlClientMock;

class ServiceTest extends MyTestCase {
	protected $subject;

	public function setUp(): void
    {
        $this->subject = new AgnoPayService(new PdoSqliteDataLayer(self::$dbh));
	}

	protected function assertPreConditions(): void
    {
		$this->assertInstanceOf(AgnoPayService::class, $this->subject);
	}
	
	/**
	* @group vendor_profile
	*/
	public function testVendorProfileDefaultState()
	{
		$this->assertEquals($this->subject->getVendorProfiles(), $this->subject->getActivatedVendorProfiles());
	}
	
	/**
	* @group vendor_profile
	*/
	public function testActivateAllVendorProfiles()
	{
		$this->subject->activateAllVendorProfiles();
		$this->assertEquals($this->subject->getVendorProfiles(), $this->subject->getActivatedVendorProfiles());
	}

	/**
	* @group vendor_profile
	*/
	public function testDeactivateAllVendors()
	{
		$this->subject->deactivateAllVendorProfiles();
		$this->assertEmpty($this->subject->getActivatedVendorProfiles());
	}

	/**
	* @group vendor_profile
	*/
	public function testNoSupportedVendorForGivenCurrency()
	{
		$this->subject->activateAllVendorProfiles();
		$this->assertEmpty($this->subject->getActivatedVendorProfiles('LKR'));
		$this->assertNotEmpty($this->subject->getActivatedVendorProfiles('MVR'));
	}

	/**
	* @group vendor_profile
	*/
	public function testSubsetVendorProfileActivate()
	{
		$supported = array_keys($this->subject->getVendorProfiles());

		$randomSubset = array_filter($supported, function($item) {
			return rand(0, 100) > 50;
		});

		if(count($randomSubset) === 0) {
			$randomSubset = [$supported[0]];
		}

		if(count($randomSubset) === count($supported)) {
			array_pop($randomSubset);
		}

		$randomSubset = array_values($randomSubset);

		$this->subject->setVendorProfileState($randomSubset);

		$this->assertEquals($randomSubset, array_keys($this->subject->getActivatedVendorProfiles()));
	}

	private function __createNewRequest()
	{
		$profiles = $this->subject->getVendorProfiles();
		
		$chosenSubset = [];
		
		foreach($profiles as $key => $value) {
			if(!isset($chosenSubset[$value['vendor']])) {
				$chosenSubset[$value['vendor']] = $key;
			}
		}

		$chosenSubset = array_values($chosenSubset);

		$this->subject->setVendorProfileState($chosenSubset);

		return $this->subject->create(1.99, 'MVR');
	}

	/**
	* @group basic
	*/
	public function testRequestCreationAndFetch()
	{
		$created = $this->__createNewRequest();
		$queried = $this->subject->get($created->getAlias());

		$this->assertEquals($created->toArray(), $queried->toArray());
	}

	/**
	* @group basic
	*/
	public function testSetMockGetMock()
	{
		$mock = new BmlClientMock();
		$this->subject->setMock('bmlconnect.getTransport', $mock);

		$supported = $this->subject->getVendorProfiles();

		$this->assertArrayHasKey('bmlconnect1', $supported);

		$impl = $this->subject->getVendorProcessImplementation($supported['bmlconnect1']);

		$this->assertInstanceOf(BmlClientMock::class, $impl->getTransport());
	}

	/**
	* @group bml
	*/
	public function testBmlProceed()
	{
		$mock = new BmlClientMock();
		$this->subject->setMock('bmlconnect.getTransport', $mock);

		$created = $this->__createNewRequest();
		$queried = $this->subject->get($created->getAlias());

		$result = $this->subject->proceed($queried->getAlias());

		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals($result->getType(), ResultModel::TYPE_INPUT_COLLECTOR);
		
		$inputModel = $result->getInputModel();
		$fields = $inputModel->getFields();
		$options = $inputModel->getOptions();

		$this->assertArrayHasKey('vendor_profile', $fields);
		
		$field = $fields['vendor_profile'];

		$this->assertArrayHasKey('label', $field);
		$this->assertArrayHasKey('validation', $field);
		$this->assertArrayHasKey('default', $field);

		$this->assertContains('bmlconnect1', array_keys($field['validation']));

		$request = PsrRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
			http_build_query(['vendor_profile' => 'bmlconnect1'], '', '&')
		);

		$result = $this->subject->proceed($queried->getAlias(), $request);

		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals(ResultModel::TYPE_INPUT_COLLECTOR, $result->getType());
		$this->assertArrayHasKey('callback_uri', $result->getInputModel()->getFields());

		$callbackUri = 'https://acme.acme/callback';

		$request = PsrRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
			http_build_query(['callback_uri' => $callbackUri], '', '&')
		);

		$result = $this->subject->proceed($queried->getAlias(), $request);

		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals(ResultModel::TYPE_INPUT_COLLECTOR_REDIRECT, $result->getType());
		$this->assertNotEmpty($result->getInputModel()->getRedirectUri());

		//$this->toConsole('Got to URL: ' . $result->getInputModel()->getRedirectUri());

		$this->assertNotEmpty($mock->transactions->getStore());

		$last = $this->subject->get([], ['id' => 'DESC']);
		
		$this->assertInstanceOf(RequestModel::class, $last);

		$request = PsrRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
			http_build_query(['empty' => 'empty'], '', '&')
		);

		$result = $this->subject->proceed($last->getAlias(), $request);
		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals(ResultModel::TYPE_COMPLETE, $result->getType());

		$this->subject->setMock('bmlconnect.getTransport', null);
	}

	/**
	* @group ooredoo
	*/
	public function testOoredooProceed()
	{
		$created = $this->__createNewRequest();
		$queried = $this->subject->get($created->getAlias());

		$result = $this->subject->proceed($queried->getAlias());

		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals($result->getType(), ResultModel::TYPE_INPUT_COLLECTOR);
		
		$inputModel = $result->getInputModel();
		$fields = $inputModel->getFields();
		$options = $inputModel->getOptions();

		$this->assertArrayHasKey('vendor_profile', $fields);
		
		$field = $fields['vendor_profile'];

		$this->assertArrayHasKey('label', $field);
		$this->assertArrayHasKey('validation', $field);
		$this->assertArrayHasKey('default', $field);

		$this->assertContains('ooredoomobilemoney1', array_keys($field['validation']));

		$request = PsrRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
			http_build_query(['vendor_profile' => 'ooredoomobilemoney1'], '', '&')
		);

		$result = $this->subject->proceed($queried->getAlias(), $request);

		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals(ResultModel::TYPE_INPUT_COLLECTOR, $result->getType());
		$this->assertArrayHasKey('callback_uri', $result->getInputModel()->getFields());

		$callbackUri = 'https://acme.acme/callback';

		$request = PsrRequest::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
			http_build_query(['callback_uri' => $callbackUri], '', '&')
		);

		$result = $this->subject->proceed($queried->getAlias(), $request);

		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals(ResultModel::TYPE_INPUT_COLLECTOR_REDIRECT, $result->getType());
		$this->assertNotEmpty($result->getInputModel()->getRedirectUri());

		//$this->toConsole('Got to URL: ' . $result->getInputModel()->getRedirectUri());

		$last = $this->subject->get([], ['id' => 'DESC']);
		
		$this->assertInstanceOf(RequestModel::class, $last);

		$supported = $this->subject->getVendorProfiles();
		$this->assertArrayHasKey('ooredoomobilemoney1', $supported);
		$impl = $this->subject->getVendorProcessImplementation($supported['ooredoomobilemoney1']);

		$txnId = '1';
		$txnStatus = '1001';

		$request = PsrRequest::create(
			'http://localhost/callback-interceptor?' . http_build_query([
				'status' => $txnStatus,
				'hash' => $impl->makeHash([
					'merchanttxnid' => $last->getAlias(),
					'transactionid' => $txnId,
					'status' => $txnStatus,
				]),
				'transactionID' => $txnId,
				'MerchantTxnID' => $last->getAlias(),
				'MFaisaTxnID' => '1',
			], '', '&'),
			'GET'
		);

		$result = $this->subject->proceed($last->getAlias(), $request);
		$this->assertInstanceOf(ResultModel::class, $result);
		$this->assertEquals(ResultModel::TYPE_COMPLETE, $result->getType());
	}
}