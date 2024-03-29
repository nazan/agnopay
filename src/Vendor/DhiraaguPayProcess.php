<?php

namespace SurfingCrab\AgnoPay\Vendor;

use Symfony\Component\HttpFoundation\Request as PsrRequest;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;
use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;
use SurfingCrab\AgnoPay\Exceptions\FalseAssumptionException;
use SurfingCrab\AgnoPay\Exceptions\MisconfigurationException;
use SurfingCrab\AgnoPay\Exceptions\ExternalSystemErrorException;
use SurfingCrab\AgnoPay\Exceptions\Recoverable\InsufficentInputException;

use SurfingCrab\AgnoPay\DataModels\RequestModel;
use SurfingCrab\AgnoPay\DataModels\StateModel;
use SurfingCrab\AgnoPay\DataModels\ResultModel;
//use SurfingCrab\AgnoPay\DataModels\InputModel;
//use SurfingCrab\AgnoPay\DataModels\RedirectInputModel;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

use Carbon\Carbon;

use SurfingCrab\AgnoPay\Service as AgnoPayService;

class DhiraaguPayProcess extends BaseVendorProcess
{
	const VENDOR_KEY = 'dhiraagupay';

	const CACHE_KEY_ACCESS_TOKEN = 'dhiraagu_pay_access_token';

    const DEFAULT_ERROR_MSG = 'We are unable to complete the transaction. Please contact customer support. Thank you.';

	const STEP_OTP_VERIFIED = 'valid-otp-captured';	
	const STEP_TXN_CREATED = 'transaction-created';

	const FORM_KEY_DEST_NUMBER = 'destination_number';
	const FORM_KEY_OTP = 'otp';

	const CURRENCY_MVR = 'MVR';

	protected $stateTransitions = [
		StateModel::STATE_INIT => [self::STEP_TXN_CREATED . '/createTransaction'],
		self::STEP_TXN_CREATED => [self::STEP_OTP_VERIFIED . '/verifyOtp', StateModel::STATE_CLEARED . '/restart'],
	];

	protected $friendlyRespMap = [
        'queryprofile:limit check failed(1140)' => "You have insufficient credit in your wallet. Please recharge and try again."
	];

	public static $httpClient;

	private function getOrderRelatedMessages(RequestModel $pcr) {
		return [
			"Product description: {$pcr->getAlias()}",
			"Bill amount: {$pcr->getCurrency()} {$pcr->getAmount()}",
		];
	}

	public function createTransaction(StateModel $currentStateModel, PsrRequest $input) {
		$request = $currentStateModel->getRequest();
		try {
			return $this->assumeTransactionCreationInputIncluded($request, $input);
		} catch(FalseAssumptionException $excp) {
			return ResultModel::getInputCollectorInstance($this->getQualifiedFormKey(self::FORM_KEY_DEST_NUMBER), [
				'destination_number' => [
					'label' => 'Destination Number',
					'validation' => '/.*/i',
					'default' => null,
				]
			], []);
		}
	}

	public function assumeTransactionCreationInputIncluded(RequestModel $requestModel, PsrRequest $input) {
		$payload = $this->service->extractData($input);

		if(!isset($payload['destination_number'])) {
			throw new FalseAssumptionException('This payment collection not yet ready to create transaction. False assumption detected.');
		}

		$destinationNumber = my_array_get($payload, 'destination_number', null);

		if(is_null($destinationNumber) || !is_scalar($destinationNumber) || preg_match('/^[0-9]{7}$/', $destinationNumber) !== 1) {
			throw new InsufficentInputException('Enter valid Dhiraagu Pay phone number.');
		}

		$config = $this->config;

		//$now = new Carbon();
		//$nowStr = $now->format('Y-m-d H:i:s');

		$reqAlias = $requestModel->getAlias();

		$amountInLD = $requestModel->getAmount();

		$amount = floatval($amountInLD / AgnoPayService::$conversionTable[static::CURRENCY_MVR]);

		$data = [
            "Username" => $config['username'],
            "MerchantKey" => $config['merchant_key'],
            "OriginationNumber" => $config['origination_number'],
            "DestinationNumber" => $destinationNumber,
            "Amount" => $amount,
            "PaymentInvoiceNumber" => substr($reqAlias, 0, 8),
            "TransactionDescription" => $reqAlias,
        ];

		\Log::debug('payment creation request payload', $data);
		
		$apiResponse = $this->post($config['payment_url'], $data, 'POST');

		$this->service->getDataAccessLayer()->pushState($requestModel->getAlias(), static::STEP_TXN_CREATED, [
            'reference_id' => $apiResponse['resultData']['referenceId'],
            'transaction_id' => $apiResponse['transactionId'],
            'destination_number' => $destinationNumber,
            'transaction_description' => $apiResponse['transactionDescription']
        ]);
		
		return ResultModel::getMutatedInstance();
	}

	public function verifyOtp(StateModel $currentStateModel, PsrRequest $input) {
		try {
			return $this->assumeOtpIncluded($currentStateModel, $input);
		} catch(FalseAssumptionException $excp) {
			return ResultModel::getInputCollectorInstance($this->getQualifiedFormKey(self::FORM_KEY_OTP), [
				'otp' => [
					'label' => 'One Time Password',
					'validation' => '/.*/i',
					'default' => null,
				]
			], [
				['label' => 'Try another vendor.', 'style' => '', 'params' => [static::INTENDED_TARGET_QUERY_PARAM_KEY => StateModel::STATE_CLEARED]]
			]);
		}
	}

	public function assumeOtpIncluded(StateModel $currentStateModel, PsrRequest $input) {
		$payload = $this->service->extractData($input);

		if(!isset($payload['otp'])) {
			throw new FalseAssumptionException('This payment collection not yet ready to confirm transaction using OTP. False assumption detected.');
		}

        $otp = my_array_get($payload, 'otp', null);
        if (!is_scalar($otp) || strlen($otp) === 0) {
			throw new InsufficentInputException('Enter valid OTP.');
        }

        $config = $this->config;
		$dhiraaguPayTransaction = $currentStateModel->getParameters();

        $body = [
            'Username' => $config['username'],
            'MerchantKey' => $config['merchant_key'],
            'ReferenceId' => $dhiraaguPayTransaction['reference_id'],
            'TransactionId' => $dhiraaguPayTransaction['transaction_id'],
            'OtpString' => $otp,
            'DestinationNumber' => $dhiraaguPayTransaction['destination_number'],
            'TransactionDescription' => $dhiraaguPayTransaction['transaction_description']
        ];

        $response = $this->post($config['otp_verify_url'], $body, 'POST');

		$requestModel = $currentStateModel->getRequest();

		return $this->service->success($requestModel->getAlias(), $dhiraaguPayTransaction);
	}

	public function restart(StateModel $currentStateModel, PsrRequest $input) {
        $request = $currentStateModel->getRequest();

        $this->service->getDataAccessLayer()->pushState($request->getAlias(), StateModel::STATE_CLEARED, []);

        return ResultModel::getMutatedInstance();
    }
	
	public function getTransport() {
		if(!empty(static::$httpClient)) {
			return static::$httpClient;
		}

		return static::$httpClient = new Client();
	}

	public function post($uri, $body = null, $method = 'POST') {
        $accessToken = $this->getAccessToken();
        $headers = [
            "Authorization" => "Bearer {$accessToken}",
		];
		
		$client = $this->getTransport();
		
		$guzzOpts = [
			'headers' => $headers,
			'form_params' => $body,
		];

		\Log::debug('guzzle request.', compact('method', 'guzzOpts'));

        try {
            $response = $client->request($method, $uri, $guzzOpts);

			\Log::debug('guzzle response.', ['payload' => $response->getBody()->getContents()]);

			if (!$this->isJsonResponse($response)) {
				\Log::debug('not a json response.');
				throw new ExternalSystemErrorException('Payload in response from Dhiraagu Pay has unexpected format. JSON data expected.');
			}

			$decoded = json_decode($response->getBody(), true);

			return $decoded;
        } catch(GuzzleException $excp) {
			\Log::debug('guzzle call error.');
			\Log::debug($excp->getResponse()->getBody()->getContents());

			throw $excp;
		} /* catch (ClientException $excp) {
			throw $excp;
			\Log::debug("Error from Dhiraagu payment gateway.", [
                'uri' => $uri,
                'response_body' => $excp->hasResponse() ? Psr7\str($excp->getResponse()) : "Response Empty",
			]);

            if($excp->hasResponse()) {
                $errorResp = json_decode($excp->getResponse()->getBody(), true);

                \Log::info("Error response from Dhiraagu", [
                    'transaction_id' => isset($errorResp['transactionId']) ? $errorResp['transactionId'] : null,
                    'transaction_status' => isset($errorResp['transactionStatus']) ? $errorResp['transactionStatus'] : null,
                    'reference_id' => isset($errorResp['referenceId']) ? $errorResp['referenceId'] : null,
                    'error_message' => isset($errorResp['resultData']) && isset($errorResp['resultData']['message']) ? $errorResp['resultData']['message'] : null,
				]);
				
                if(isset($errorResp['resultData']) && isset($errorResp['resultData']['message'])) {
					$msgKey = trim(strtolower($errorResp['resultData']['message']));
					
					$errorRespMsg = array_key_exists($msgKey, $this->friendlyRespMap) ? $this->friendlyRespMap[$msgKey] : static::DEFAULT_ERROR_MSG;
					
					throw new RecoverableErrorException($errorRespMsg, 0, $excp, [
						'title' => 'Dhiraagu Pay systems error',
						'message' => $errorRespMsg
					]);
                }
            }
			
			throw new RecoverableErrorException("Unexpected response while communicating with Dhiraagu Pay systems.", 0, $excp, [
				'title' => 'Dhiraagu Pay systems error',
				'message' => static::DEFAULT_ERROR_MSG
			]);
        }*/
    }

    private function getAccessToken()
    {
        if ($this->service->getCacher()->has(self::CACHE_KEY_ACCESS_TOKEN)) {
            $response = json_decode($this->service->getCacher()->get(self::CACHE_KEY_ACCESS_TOKEN), true);

            $now = Carbon::now();
            $expiresAt = Carbon::parse($response['.expires']);

            if ($now->gt($expiresAt)) {
                $this->service->getCacher()->forget(self::CACHE_KEY_ACCESS_TOKEN);
                return $this->getAccessToken();
            }

			\Log::debug('token from cache', [$response['access_token']]);
            return $response['access_token'];
        }
		
		$config = $this->config;

		$client = $this->getTransport();

        try {
            $response = $client->request('POST', $config['auth_url'], [
                'headers' => [],
                'form_params' => [
                    'grant_type' => 'password',
                    'username' => $config['auth_username'],
                    'password' => $config['auth_password']
                ]
            ]);

            if ($this->isJsonResponse($response)) {
				$response = json_decode($response->getBody()->getContents(), true);
            }
			
			if(!isset($response['access_token'])) {
				throw new ExternalSystemErrorException('No access token in response from Dhiraagu Pay systems.');
			}

			if(isset($response['.expires'])) {
				\Log::debug('just before caching token.', compact('response'));
				$this->service->getCacher()->put(self::CACHE_KEY_ACCESS_TOKEN, json_encode($response));
			}

			return $response['access_token'];
        } catch (ClientException $excp) {
            $response = $excp->getResponse();
            $errorCode = $response->getStatusCode();
            //$responseBody = $response->getBody()->getContents();
			
			throw new ExternalSystemErrorException("Access token retrieval call to Dhiraagu Pay systems responded with code '$errorCode'.", null, $excp);
        }
	}

	private function isJsonResponse($response) {
		if($content_types = $response->getHeader('Content-Type')) {
			foreach($content_types as $content_type) {
				if(in_array('application/json', explode(';', $content_type))) {
					return true;
				}
			}
		}

		return false;
	}

	public function supportedCurrencies(): array
    {
        return [static::CURRENCY_MVR];
    }

	// Callback not used in this payment processor.
	public function callbackIsAuthentic($payload, $isWebhook = false) {
		// See here if included signature is valid. Return true only if it is valid.

		if($isWebhook) {
			return $this->verifyWebhookCallSignature($payload);
		}
		
		return false;
	}

	public function extractPaymentCollectionRequestIdentifier($payload = null, $isWebhook = false): array {
		throw new InvalidInputException("Chosen payment processor does not support any method of callbacks.");
	}

	public function verifyWebhookCallSignature($payload) {
		return false;
	}
}