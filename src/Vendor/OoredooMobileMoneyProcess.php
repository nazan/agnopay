<?php

namespace SurfingCrab\AgnoPay\Vendor;

use Symfony\Component\HttpFoundation\Request as PsrRequest;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;
use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;
use SurfingCrab\AgnoPay\Exceptions\FalseAssumptionException;

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

	const FORM_KEY_MERCHANT_SPEC = 'merchant_spec';

	protected $stateTransitions = [
		'initiated' => [StateModel::STATE_SUCCESS . '/redirectToVendorSystem', StateModel::STATE_CLEARED . '/restart'],
	];

	protected $errorCodes = [
		'4001' => "Unauthorized transaction.",
		'4002' => "Incomplete Information.",
		'2004' => "Internal fault or exception.",
	];

	protected $recoverableErrorCodes = [];

	protected $confirmedState = 'success-callback-captured';

	public function redirectToVendorSystem(StateModel $currentStateModel, PsrRequest $input) {
		$requestModel = $currentStateModel->getRequest();

		try {
			return $this->assumeCallbackFromVendor($requestModel, $input);
		} catch(FalseAssumptionException $excp) {
			$amount = $requestModel->getAmount();
			$amount = str_pad("$amount", 7, "0", STR_PAD_LEFT);
			
			$config = $this->config;

			$callbackUri = $config['callback_uri'];
			
			$plainText = "{$config['merchant_id']}{$config['merchant_pin']}{$config['merchant_key']}{$requestModel->getAlias()}{$amount}";
			$signature = hash("sha1", $plainText);

			return ResultModel::getInputCollectorRedirectInstance($this->getQualifiedFormKey(self::FORM_KEY_MERCHANT_SPEC), [
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
			], [], $config['url']);
		}
	}

	private function assumeCallbackFromVendor(RequestModel $requestModel, PsrRequest $input) {
		$inputData = $this->service->extractData($input);
			
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
				throw new VendorFailureException($errorMessage, 0, null, [
					['label' => 'Try another vendor.', 'style' => '', 'params' => [static::INTENDED_TARGET_QUERY_PARAM_KEY => StateModel::STATE_CLEARED]]
				]);
			}
			
			throw new InvalidInputException($errorMessage);
		}

		if(!isset($inputData['status']) || empty($inputData['status'])) {
			throw new FalseAssumptionException("Invalid response from vendor.");
		}

		if($inputData['status'] !== self::SUCCESS_CODE) {
			throw new InvalidInputException("Unexpected response from external system. External system API seems to have updated.");
		}

		return $this->service->success($requestModel->getAlias(), $originalPayload);
	}

	public function restart(StateModel $currentStateModel, PsrRequest $input) {
        $request = $currentStateModel->getRequest();

        $this->service->getDataAccessLayer()->pushState($request->getAlias(), StateModel::STATE_CLEARED, []);

        return ResultModel::getMutatedInstance();
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

	public function supportedCurrencies(): array
    {
        return ['MVR'];
    }

	public function callbackIsAuthentic($payload, $isWebhook = false) {
		$inputData = lowerAssocKeys($payload);
		
		// See here if included signature is valid. Return true only if it is valid.
		$includedSig = my_array_get($inputData, 'hash', null);

		if(is_null($includedSig)) {
			return $this->isErrorPayload($inputData);
		}

		$hash = $this->makeHash($inputData);


		return $hash === $includedSig;
	}

	public function extractPaymentCollectionRequestIdentifier($input = null, $isWebhook = false): array {
		if($isWebhook) {
			throw new InvalidInputException("Chosen payment processor does not support webhook calls.");
		}

		$inputLower = lowerAssocKeys($input);

		$alias = my_array_get($inputLower, 'merchanttxnid', null);

		if(is_null($alias)) {
			throw new InvalidInputException('Unable to extract payment request identifier.');
		}

		return ['alias' => $alias];
	}

	public function extractIntendedTargetState(PsrRequest $request, RequestModel $pcr) {
		$targetState = parent::extractIntendedTargetState($request, $pcr);

		if(!is_null($targetState)) {
			return $targetState;
		}

		$inputData = $this->service->extractData($request);
		
		$inputData = lowerAssocKeys($inputData);

		if($this->isErrorPayload($inputData)) {
			if($inputData['status'] === self::USER_CANCELLED_CODE) {
				return StateModel::STATE_CLEARED;
			}

			//throw new InvalidInputException("{$inputData['message']} / status code '{$inputData['status']}'");
		}

		return null;
	}
}