<?php

namespace SurfingCrab\AgnoPay\Vendor;

use GuzzleHttp\Psr7\Request as PsrRequest;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;
use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;

use SurfingCrab\AgnoPay\DataModels\RequestModel;
use SurfingCrab\AgnoPay\DataModels\StateModel;
use SurfingCrab\AgnoPay\DataModels\ResultModel;
//use SurfingCrab\AgnoPay\DataModels\InputModel;
//use SurfingCrab\AgnoPay\DataModels\RedirectInputModel;

class OoredooMobileMoneyProcess extends BaseVendorProcess
{
	const VENDOR_KEY = 'ooredoomobilemoney';

	const EXTERNAL_SYSTEM_ERROR_TITLE = "Ooredoo systems error";

	const USER_CANCELLED_CODE = '4003';

	const SUCCESS_CODE = '1001';

	protected $stateTransitions = [
		'initiated' => ['callback-collected/collectCallbackUri', StateModel::STATE_CLEARED . '/restart'],
		'callback-collected' => [StateModel::STATE_SUCCESS . '/redirectToOoredooSystem', StateModel::STATE_CLEARED . '/restart']
	];

	protected $errorCodes = [
		'4001' => "Unauthorized transaction.",
		'4002' => "Incomplete Information.",
		'2004' => "Internal fault or exception.",
	];

	protected $recoverableErrorCodes = [];

	protected $confirmedState = 'success-callback-captured';

	public function collectCallbackUri(StateModel $currentStateModel, PsrRequest $input = null) {
		$paymentRequest = $currentStateModel->getRequest();

		$config = $this->config;

		if(!is_null($input)) {
			$inputData = $this->service->extractData($input);
            if(!isset($inputData['callback_uri']) || empty($inputData['callback_uri'])) {
                throw new InvalidInputException("Invalid callback URI for Ooredoo Mobile Money redirect.");
            }

            $request = $currentStateModel->getRequest();

            $this->service->getDataAccessLayer()->pushState($request->getAlias(), 'callback-collected', [
                'callback_uri' => $inputData['callback_uri'],
            ]);

            return $this->proceed($request);
		}

		return ResultModel::getInputCollectorInstance([
            'callback_uri' => [
                'label' => 'Callback URI',
                'validation' => '/.*/i',
                'default' => null,
            ],
		]);
	}

	public function redirectToOoredooSystem(StateModel $currentStateModel, PsrRequest $input = null) {
		$requestModel = $currentStateModel->getRequest();

		$config = $this->config;

		if(!is_null($input)) {
			$inputData = $this->service->extractData($input);

			if(!$this->callbackIsAuthentic($inputData)) {
				throw new InvalidMethodCallException("Attack detected. Aborting payment request.");
			}
			
			$originalPayload = $inputData;

			$inputData = lowerAssocKeys($inputData);
	
			if($this->isErrorPayload($inputData)) {
				if(isset($inputData['message'])) {
					$errorMessage = $inputData['message'];
				} else {
					if(isset($this->errorCodes[$inputData['status']])) {
						$errorMessage = $this->errorCodes[$inputData['status']];
					} else {
						$errorMessage = 'Unexpected error code from vendor.';
					}
				}
	
				if(in_array($inputData['status'], $this->recoverableErrorCodes)) {
					return ResultModel::getFeedbackInstance($errorMessage);
				}
				
				throw new InvalidInputException($errorMessage);
			}

			if(!isset($inputData['status']) || empty($inputData['status'])) {
                throw new InvalidInputException("Invalid response from Ooredoo Mobile Money.");
			}
	
			if($inputData['status'] !== self::SUCCESS_CODE) {
				throw new InvalidInputException("Unexpected response from external system. External system API seems to have updated.");
			}

			return $this->service->success($requestModel->getAlias(), $originalPayload);
		}

		try {
			$callbackCollectedState = $this->service->getDataAccessLayer()->getLastStateMatching($requestModel->getAlias(), ['state' => 'callback-collected']);
		} catch(InvalidMethodCallException $excp) {
			throw new MissingDataException("Request of alias '{$requestModel->getAlias()}' must have a `callback-collected` state at this point.", 0, $excp);
		}
		
		$callbackUri = $callbackCollectedState->getParameters()['callback_uri'];

		$amount = $requestModel->getAmount();
		$amount = str_pad("$amount", 7, "0", STR_PAD_LEFT);
		
        $plainText = "{$config['merchant_id']}{$config['merchant_pin']}{$config['merchant_key']}{$requestModel->getAlias()}{$amount}";
		$signature = hash("sha1", $plainText);

		return ResultModel::getInputCollectorRedirectInstance([
			'MerID' => [
                'label' => 'Merchant ID',
                'validation' => '/.*/i',
                'default' => $config['merchant_id'],
			],
			'TxnId' => [
                'label' => 'Transaction ID',
                'validation' => '/.*/i',
                'default' => $requestModel->getAlias(),
			],
			'PayAmt' => [
                'label' => 'Payment Amount',
                'validation' => '/.*/i',
                'default' => $amount,
			],
			'MerRespURL' => [
                'label' => 'Callback URI',
                'validation' => '/.*/i',
                'default' => $callbackUri,
			],
			'Signature' => [
                'label' => 'Signature',
                'validation' => '/.*/i',
                'default' => $signature,
            ],
		], $config['url']);
	}

	/*
	public function restart($currentStep, $payload) {
		$paymentRequest = $currentStep->paymentRequest;

		$paymentRequest = $this->paymentRequestService->setPaymentRequestState($paymentRequest, PaymentRequest::STATE_PENDING);

		$processors = explode(';', $paymentRequest->payment_processors);

		if(in_array(static::PROCESSOR_KEY, $processors)) {
			$removeIndex = array_search(static::PROCESSOR_KEY, $processors);
			array_splice($processors, $removeIndex, 1);
			$paymentRequest->payment_processors = implode(';', $processors);
			$paymentRequest->save();
		}

		if($currentStep->sequence > 0) {
			$this->updateState($paymentRequest, 'init', ['state_before_restart' => $currentStep->state]);
		}

		return $this->defaultRedirect($paymentRequest->alias);
	}
	*/

	public function callbackIsAuthentic($inputData) {
		$inputData = lowerAssocKeys($inputData);
		
		// See here if included signature is valid. Return true only if it is valid.
		$includedSig = my_array_get($inputData, 'hash', null);

		if(is_null($includedSig)) {
			return $this->isErrorPayload($inputData);
		}

		$hash = $this->makeHash($inputData);


		return $hash === $includedSig;
	}

	public function makeHash($inputData) {
		$config = $this->config;
		
		$plainText = my_array_get($inputData, 'merchanttxnid', '<empty>')
					. my_array_get($inputData, 'transactionid', '<empty>')
					. $config['merchant_key']
					. my_array_get($inputData, 'status', '<empty>');
					
		return hash('sha1', $plainText, false);
	}

	private function isErrorPayload($inputData) {
		$inputData = lowerAssocKeys($inputData);

		$errorFields = ['status', 'transactionid', 'merchanttxnid', 'message', 'datetime'];

		foreach($errorFields as $field) {
			if(!isset($inputData[$field])) {
				return false;
			}
		}

		return !isset($inputData['hash']);
	}

	public function extractIntendedTargetState($inputData) {
		$inputData = lowerAssocKeys($inputData);

		if($this->isErrorPayload($inputData)) {
			if($inputData['status'] === self::USER_CANCELLED_CODE) {
				return StateModel::STATE_CLEARED;
			}

			throw new InvalidInputException("{$inputData['message']} / status code '{$inputData['status']}'");
		}

		return StateModel::STATE_SUCCESS;
	}

	public function supportedCurrencies(): array
    {
        return ['MVR'];
    }
}