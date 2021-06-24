<?php

namespace SurfingCrab\AgnoPay\Vendor;

use Symfony\Component\HttpFoundation\Request as PsrRequest;

use SurfingCrab\AgnoPay\Exceptions\MisconfigurationException;
use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;
use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;
use SurfingCrab\AgnoPay\Exceptions\MissingDataException;

use SurfingCrab\AgnoPay\DataModels\RequestModel;

use SurfingCrab\AgnoPay\Service as AgnoPayService;

abstract class BaseVendorProcess
{
    protected $service;

    protected $config;

    protected $label;

    protected $stateTransitions;

	const INTENDED_TARGET_QUERY_PARAM_KEY = '__agno_target';

    public function __construct(AgnoPayService $service, $config, $label)
    {
        $this->service = $service;
        $this->config = $config;
        $this->label = $label;
    }

    public function proceed(RequestModel $request, PsrRequest $input)
    {
		$currency = $request->getCurrency();

		if(!in_array($currency, $this->supportedCurrencies())) {
			throw new InvalidInputException("Given request currency of '$currency' not supported by chosen vendor.");
		}

		try {
			$currentStateModel = $this->service->getDataAccessLayer()->getCurrentState($request->getAlias());
		} catch(InvalidMethodCallException $excp) {
			throw new MissingDataException("This should never happen. Request of alias '{$request->getAlias()}' does not have any associated state. At least 'initiated' state must be associated at this point.", 0, $excp);
		}

		$currentState = $currentStateModel->getState();
		$targetState = $this->extractIntendedTargetState($input, $request);
		
		$routine = $this->getNextAction($currentState, $targetState);

		$vendorImplClass = get_class($this);

		\Log::debug('Calling vendor routine.', compact('vendorImplClass', 'routine'));

        return $this->callRoutine($routine, $currentStateModel, $input);
	}

    private function getNextAction($currentState, $targetState = null) {
		if(!isset($this->stateTransitions[$currentState])) {
			throw new MisconfigurationException("Unable to deduce next action for current state '$currentState'. Specify state/method for next step in \$stateTransitions abstract property.");
		}

		$possibleTargets = is_array($this->stateTransitions[$currentState]) ? $this->stateTransitions[$currentState] : [$this->stateTransitions[$currentState]];

		if(!empty($targetState)) { // target state explicit.
			foreach($possibleTargets as $eachPossibility) {
				$parts = explode('/', $eachPossibility, 2);

				if(count($parts) === 2 && $parts[0] === $targetState) {
					return $parts[1];
				}
			}
		}

		if(strstr($possibleTargets[0], '/') !== false) {
			$parts = explode('/', $possibleTargets[0], 2);
			return $parts[1];
		}

		return $possibleTargets[0];
	}

	private function callRoutine($routine, $currentStateModel, $input) {
		if(!method_exists($this, $routine)) {
			$className = get_class($this);
			throw new MisconfigurationException("Handler method '$routine' does not exist in vendor process class '$className'.");
		}
		
		return $this->$routine($currentStateModel, $input);
	}

	public function getQualifiedFormKey($formKey) {
		return static::VENDOR_KEY . '.' . $formKey;
	}

	public function getMock($key)
	{
		$vendorKey = static::VENDOR_KEY;
		return $this->service->getMock("$vendorKey.$key");
	}

	public abstract function supportedCurrencies(): array;

	
	public abstract function callbackIsAuthentic($payload, $isWebhook = false);
	public abstract function extractPaymentCollectionRequestIdentifier($payload = null, $isWebhook = false): array;
	
	public function extractIntendedTargetState(PsrRequest $request, RequestModel $pcr) {
		$method = strtolower($request->getMethod());

		if($method === 'get' && $request->query->has(self::INTENDED_TARGET_QUERY_PARAM_KEY)) {
			return $request->query->get(self::INTENDED_TARGET_QUERY_PARAM_KEY);
		}

		return null;
	}
}