<?php

namespace SurfingCrab\AgnoPay\Vendor;

use Symfony\Component\HttpFoundation\Request as PsrRequest;

use BMLConnect\Client;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;
use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;
use SurfingCrab\AgnoPay\Exceptions\ExternalSystemErrorException;
use SurfingCrab\AgnoPay\Exceptions\DeliberateException;

use SurfingCrab\AgnoPay\DataModels\RequestModel;
use SurfingCrab\AgnoPay\DataModels\StateModel;
use SurfingCrab\AgnoPay\DataModels\ResultModel;
//use SurfingCrab\AgnoPay\DataModels\InputModel;
//use SurfingCrab\AgnoPay\DataModels\RedirectInputModel;

class BmlConnectProcess extends BaseVendorProcess
{
    const VENDOR_KEY = 'bmlconnect';

    protected $stateTransitions = [
        'initiated' => 'transaction-created/createTransaction',
        'transaction-created' => [StateModel::STATE_SUCCESS . '/redirectToBmlConnectGateway', StateModel::STATE_CLEARED . '/restart']
    ];

    public function getTransport() {
        if(!is_null($client = $this->getMock(__FUNCTION__))) {
            return $client;
        }

		$config = $this->config;
		return new Client($config['api_key'], $config['app_id'], $config['env']);
	}

	public function createTransaction(StateModel $currentStateModel, PsrRequest $input = null) {
        if(!is_null($input)) {
            $inputData = $this->service->extractData($input);
            if(!isset($inputData['callback_uri']) || empty($inputData['callback_uri'])) {
                throw new InvalidInputException("Invalid callback URI for BML Connect redirect.");
            }

            $request = $currentStateModel->getRequest();

            $client = $this->getTransport();

            $json = [
                "currency" => $request->getCurrency(),
                "amount" => $request->getAmount(),
                "localId" => $request->getAlias(),
                "customerReference" => $request->getAlias(),
                "redirectUrl" => $inputData['callback_uri'], // Optional redirect after payment completion
            ];

            $transaction = $client->transactions->create($json);

            $this->service->getDataAccessLayer()->pushState($request->getAlias(), 'transaction-created', [
                'provider_txn_id' => $transaction->id,
                'payment_collection_url' => $transaction->url,
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
    
    public function redirectToBmlConnectGateway(StateModel $currentStateModel, PsrRequest $input = null) {
        if(!is_null($input)) {
            $requestModel = $currentStateModel->getRequest();

            try {
                $lastTxnCreatedState = $this->service->getDataAccessLayer()->getLastStateMatching($requestModel->getAlias(), ['state' => 'transaction-created']);
            } catch(InvalidMethodCallException $excp) {
                throw new MissingDataException("Request of alias '{$requestModel->getAlias()}' must have a `transaction-created` state at this point.", 0, $excp);
            }
            
            $lastProviderTxnId = $lastTxnCreatedState->getParameters()['provider_txn_id'];

            $txnData = $this->getTransaction($lastProviderTxnId);

            if(strtolower($txnData['state']) !== 'confirmed') {
                throw new InvalidInputException("Unexpected transaction state encountered while handling callback from BML Connect. Payment collection failed.");
            }

            return $this->service->success($requestModel->getAlias(), $txnData);
        }

		return ResultModel::getInputCollectorRedirectInstance([], $currentStateModel->getParameters()['payment_collection_url']);
    }
    
    public function getTransaction($transactionId) {
		$client = $this->getTransport();

        try {
            $transaction = $client->transactions->get($transactionId);
        } catch(\Exception $excp) {
            throw new ExternalSystemErrorException("Failed to get transaction details for ID '$transactionId' from BML Systems.", 0, $excp);
        }

        return json_decode(json_encode($transaction) ,true);
    }
    
    public function supportedCurrencies(): array
    {
        return ['USD', 'MVR'];
    }

    public function verifyWebhookCallSignature($payload)
	{
		return true;
	}

    public function callbackIsAuthentic($payload, $isWebhook = false) {
		// See here if included signature is valid. Return true only if it is valid.

		if($isWebhook) {
			return $this->verifyWebhookCallSignature($payload);
		}

		return true;
	}

    public function extractPaymentCollectionRequestIdentifier($input = null, $isWebhook = false): array { // $isWebhook is not used since the method is the same for both 301 redirect callback and webhook call.
        if(!isset($input['transactionId']) || empty($input['transactionId'])) {
            throw new InvalidInputException("Invalid transaction ID in BML Connect callback payload.");
        }

        $txnId = array_get($input, 'transactionId', null);

		$transaction = $this->getTransaction($txnId, false);

		return ['alias' => $transaction->localId];
	}

	public function extractIntendedTargetState(PsrRequest $request, RequestModel $pcr) {
        $payload = $this->service->extractData($request);
		$state = strtolower(array_get($payload, 'state', ''));
		
		if($state === 'cancelled') {
			$keys = $pcr->getVendorProfiles();

			if(count($keys) === 1) {
				throw new DeliberateException("Transaction cancelled upon request from customer.");
			}

			return 'init';
		}

		// Multiple API calls to retrieve the transaction can be avoided if there is a way to validate the signature sent in the callback.
        $txnId = array_get($payload, 'transactionId', null);

		$transaction = $this->getTransaction($txnId, false);
		
		if($state === 'confirmed' && strtolower($transaction->state) === 'confirmed') {
			return 'success-callback-captured';
		}

		throw new InvalidInputException("Unexpected transaction state in given transaction ID within callback payload.");
	}
}