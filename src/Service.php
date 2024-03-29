<?php

namespace SurfingCrab\AgnoPay;

use Symfony\Component\HttpFoundation\Request as PsrRequest;

use SurfingCrab\AgnoPay\Exceptions\Exception as AgnoPayException;
use SurfingCrab\AgnoPay\Exceptions\DataLayerIncorrectImplementationException;
use SurfingCrab\AgnoPay\Exceptions\MissingDataException;
use SurfingCrab\AgnoPay\Exceptions\MisconfigurationException;
use SurfingCrab\AgnoPay\Exceptions\UnexpectedRequestModelStateException;
use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;
use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;
use SurfingCrab\AgnoPay\Exceptions\UnimplementedMethodException;
use SurfingCrab\AgnoPay\Exceptions\FalseAssumptionException;

use SurfingCrab\AgnoPay\DataModels\DataLayerInterface;

use SurfingCrab\AgnoPay\DataModels\RequestModel;
use SurfingCrab\AgnoPay\DataModels\StateModel;
use SurfingCrab\AgnoPay\DataModels\ResultModel;

use SurfingCrab\AgnoPay\DataModels\CacherInterface;
use SurfingCrab\AgnoPay\DataModels\PhpSessionCacher;

use SurfingCrab\AgnoPay\Vendor\BaseVendorProcess;

class Service {
    const VENDOR_BML_CONNECT = 'bmlconnect';
    const VENDOR_OOREDOO_MM = 'ooredoomobilemoney';
    const VENDOR_DHIRAAGU_PAY = 'dhiraagupay';

    public static $vendorKeys = [
        self::VENDOR_BML_CONNECT,
        self::VENDOR_OOREDOO_MM,
        self::VENDOR_DHIRAAGU_PAY,
    ];

    const FORM_KEY_VENDOR_CHOICES = 'vendor_choices';

    public static $conversionTable = [
        'USD' => 100,
        'MVR' => 100,
    ];

    protected $mocks;

    const DEFAULT_EXPIRES_IN = 1500; // In seconds.

    protected $vendorProfileState = [];

    protected $dl;

    protected $expiresIn;

    protected $autoAmountConversion;

    protected $cacher;

    public function __construct(DataLayerInterface $dl, CacherInterface $cacher = null)
    {
        $this->dl = $dl;
        
        $this->mocks = [];

        $this->autoAmountConversion = true;

        $this->expiresIn = self::DEFAULT_EXPIRES_IN;

        if(is_null($cacher)) {
            $this->cacher = new PhpSessionCacher();
        } else {
            $this->cacher = $cacher;
        }

        $this->setVendorProfileDefaultState();
    }

    public function setAutoAmountConversion(bool $state)
    {
        $this->autoAmountConversion = $state;
    }

    public function getDataAccessLayer()
    {
        return $this->dl;
    }

    public function setExpiresIn($expiresIn) {
        $this->expiresIn = $expiresIn;
    }

    public function getExpiresIn() {
        return $this->expiresIn;
    }

    public function getSupportedVendors()
    {
        return self::$vendorKeys;
    }

    public function getVendorProfiles()
    {
        $vendorProfiles = $this->dl->getVendorProfiles();

        $supported = $this->getSupportedVendors();

        // Check for valid data.
        foreach($vendorProfiles as $key => $profile) {
            if(!isset($profile['label']) || !is_string($profile['label'])) {
                throw new DataLayerIncorrectImplementationException("Vendor profile '$key' does not specify a valid label with key 'label'.");
            }

            if(!isset($profile['vendor']) || !is_string($profile['vendor']) || !in_array($profile['vendor'], $supported)) {
                throw new DataLayerIncorrectImplementationException("Vendor profile '$key' does not specify a valid vendor with key 'vendor'.");
            }

            if(!isset($profile['config']) || !is_array($profile['config'])) {
                throw new DataLayerIncorrectImplementationException("Vendor profile '$key' does not specify valid configuration with key 'config'.");
            }
        }

        return $vendorProfiles;
    }

    public function getActivatedVendorProfiles($currency = null)
    {
        $enabledKeys = array_filter(array_keys($this->vendorProfileState), function($item) {
            return $this->vendorProfileState[$item];
        });

        $allProfiles = $this->getVendorProfiles();

        $enabledValues = array_map(function($itemKey) use($allProfiles) {
            return $allProfiles[$itemKey];
        }, $enabledKeys);

        $enabled = array_combine($enabledKeys, $enabledValues);

        if(is_null($currency)) {
            return $enabled;
        }

        $filtered = [];
        foreach($enabled as $profileKey => $vendorProfile) {
            $implementation = $this->getVendorProcessImplementation($vendorProfile);
            if(in_array($currency, $implementation->supportedCurrencies())) {
                $filtered[$profileKey] = $vendorProfile;
            }
        }

        return $filtered;
    }

    public function setVendorProfileDefaultState()
    {
        $this->activateAllVendorProfiles();
    }

    public function setVendorProfileState($subjects, $state = true, $inverseOthers = true)
    {
        $valid = $this->getVendorProfiles();

        if($inverseOthers) {
            foreach($valid as $vendorKey => $vendorConfig) {
                $this->vendorProfileState[$vendorKey] = !$state;
            }
        }

        $validKeys = array_keys($valid);

        foreach($subjects as $vendorKey) {
            if(in_array($vendorKey, $validKeys)) {
                $this->vendorProfileState[$vendorKey] = $state;
            }
        }
    }

    public function activateAllVendorProfiles()
    {
        $this->setVendorProfileState(array_keys($this->getVendorProfiles()), true, false);
    }

    public function deactivateAllVendorProfiles()
    {
        $this->setVendorProfileState(array_keys($this->getVendorProfiles()), false, false);
    }

    public function create($amount, $currency, callable $postActions): RequestModel {
        $vendorProfiles = $this->getActivatedVendorProfiles($currency);

        if(empty($vendorProfiles)) {
            throw new MisconfigurationException("Could not find a payment vendor for given currency.");
        }

        if($this->autoAmountConversion) {
            if(!isset(self::$conversionTable[$currency])) {
                throw new MisconfigurationException("Currency conversion to lower denomination could not carried out. Rate unknown for currency '$currency'.");
            }

            $amount = intval(round($amount, 2) * self::$conversionTable[$currency]);
        } elseif(is_float($amount)) {
            throw new InvalidMethodCallException("Amount must be already converted to lowest denomination (i.e. integer) while auto conversion (to lowest denomination) is turned off.");
        }

        try {
            $inst = $this->dl->createPaymentRequest(implode(',', array_keys($vendorProfiles)), $this->expiresIn, $amount, $currency, $postActions);

            $asArray = $inst->toArray();

            return $inst;
        } catch(MissingDataException $excp) {
            throw new UnexpectedRequestModelStateException("Make sure to properly persist all required fields in payment request model.", 0, $excp);
        }
    }

    public function get($criteria, $sort = []): RequestModel
    {
        return $this->dl->getPaymentRequest($criteria, $sort);
    }

    public function proceed($alias, PsrRequest $input): ResultModel
    {
        $request = $this->get($alias);

        try {
            return $this->assumeRequestAlreadyConcluded($request);
        } catch(FalseAssumptionException $excp) {
            try {
                return $this->assumeRequestAlreadyInitiated($request, $input);
            } catch(FalseAssumptionException $excp) {
                try {
                    return $this->assumeRequestInitiationInputIncluded($request, $input);
                } catch(FalseAssumptionException $excp) {
                    return $this->getInitiationInputFormDescription($request);
                }
            }
        }
    }

    private function assumeRequestAlreadyConcluded(RequestModel $request) {
        try {
            $currentState = $this->dl->getCurrentState($request->getAlias());

            return ResultModel::getConclusionResultInstance($currentState);
        } catch(InvalidMethodCallException | InvalidInputException $excp) {
            throw new FalseAssumptionException("Request completion assumed prematurely. Further processing is required.", 0, $excp);
        }
    }

    private function assumeRequestAlreadyInitiated(RequestModel $request, PsrRequest $input) {
        try {
            $lastInitiatedState = $this->dl->getLastInitiatedState($request->getAlias());
        } catch(InvalidMethodCallException $excp) {
            throw new FalseAssumptionException("Request 'in processing' assumed prematurely. Initiation task is required.", 0, $excp);
        }
        
        $initiatedTime = $lastInitiatedState->getInitiatedTime();
        
        $now = new \DateTime();
        if($now->getTimestamp() >= $initiatedTime->getTimestamp() + $request->getExpiresIn()) {
            return $this->failed($request->getAlias(), [
                'code' => 0,
                'message' => "Request expired.",
            ]);
        }

        $vendorProfileKey = $lastInitiatedState->getChosenVendorProfile();

        $vendorProfiles = $this->getVendorProfiles();

        $vendorProfile = $vendorProfiles[$vendorProfileKey];
        
        $vendorProcessImpl = $this->getVendorProcessImplementation($vendorProfile);

        try {
            return $vendorProcessImpl->proceed($request, $input);
        } catch(RecoverableException $excp) {
            return ResultModel::getFeedbackInstance($excp->getMessage(), $excp->getOptions());
        } catch(AgnoPayException $excp) {
            return $this->failed($request->getAlias(), [
                'code' => 0,
                'message' => $excp->getMessage(),
            ]);
        }
    }

    private function assumeRequestInitiationInputIncluded(RequestModel $request, PsrRequest $input) {
        $payload = $this->extractData($input);

        $failed = $this->dl->getFailedVendorProfiles($request->getAlias());        
                    
        if(!isset($payload['vendor_profile']) || empty($payload['vendor_profile']) || !in_array($payload['vendor_profile'], $request->getVendorProfiles()) || in_array($payload['vendor_profile'], $failed)) {
            throw new FalseAssumptionException("Vendor profile choice is invalid or missing.");
        }

        $now = new \DateTime();
        $this->dl->pushState($request->getAlias(), StateModel::STATE_INIT, [
            'initiated_at' => $now->format('Y-m-d H:i:s'),
            'vendor_profile' => $payload['vendor_profile'],
        ]);
            
        return ResultModel::getMutatedInstance();
    }

    private function getInitiationInputFormDescription(RequestModel $request) {
        $allVendorProfiles = $this->getVendorProfiles();
        $failed = $this->dl->getFailedVendorProfiles($request->getAlias());        

        \Log::debug('failed providers (vendors).', compact('failed'));

        $profileValidation = [];
        foreach($request->getVendorProfiles() as $profileKey) {
            if(isset($allVendorProfiles[$profileKey]['label']) && !in_array($profileKey, $failed)) {
                $profileValidation[$profileKey] = $allVendorProfiles[$profileKey]['label'];
            }
        }

        return ResultModel::getInputCollectorInstance(self::FORM_KEY_VENDOR_CHOICES, [
            'vendor_profile' => [
                'label' => 'Processor',
                'validation' => $profileValidation,
                'default' => null,
            ],
        ], []);
    }

    public function extractData(PsrRequest $input)
    {
        $method = strtolower($input->getMethod());

        if($method === 'post') {
            $contentType = $input->headers->get('Content-Type');

            if(is_array($contentType)) {
                // Convert all string elements to lower case.
                $contentType = array_map(function($item) {
                    return is_string($item) ? strtolower($item) : $item;
                }, $contentType);
            } else {
                $contentType = [is_string($contentType) ? strtolower($contentType) : $contentType];
            }

            if(in_array('application/x-www-form-urlencoded', $contentType)) {
                parse_str($input->getContent(), $output);
                return $output;
            } elseif(in_array('application/json', $contentType)) {
                return json_decode($input->getContent(), true);
            }
        } elseif($method === 'get') {
            parse_str($input->getQueryString(), $output);
            return $output;
        }
        
        throw new InvalidInputException("Not a valid input. Pass in a PSR7 Request object of content type either 'application/x-www-form-urlencoded' or 'application/json'");
    }

    public function getVendorProcessImplementationForRequest(RequestModel $pcr) {
        $lastInitiatedState = $this->dl->getLastInitiatedState($pcr->getAlias());

        $profileKey = $lastInitiatedState->getChosenVendorProfile();

        $profiles = $this->getActivatedVendorProfiles($pcr->getCurrency());

        return $this->getVendorProcessImplementation($profiles[$profileKey]);
    }

    public function getVendorProcessImplementation($vendorProfile)
    {
        $path = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'Vendor');
        $allFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        $phpFiles = new \RegexIterator($allFiles, '/\.php$/');

        $namespaceName = (new \ReflectionClass(__CLASS__))->getNamespaceName();
        
        foreach ($phpFiles as $phpFile) {
            $className = "$namespaceName\\Vendor\\" . str_replace('.php', '', $phpFile->getBaseName());

            if(!class_exists($className)) {
                continue;
            }

            $reflector = new \ReflectionClass($className);

            if($reflector->getShortName() === 'BaseVendorProcess') {
                continue;
            }

            if(!$reflector->isSubclassOf(BaseVendorProcess::class)) {
                continue;
            }

            $ikey = $reflector->getConstant('VENDOR_KEY');

            if($ikey === $vendorProfile['vendor']) {
                return $reflector->newInstance($this, $vendorProfile['config'], $vendorProfile['label']);
            }
        }

        throw new UnimplementedMethodException("Unable to deduce vendor implementation class for vendor key '{$vendorProfile['vendor']}'.");
    }

    public function discernPaymentCollectionRequest($vendorProfileKey, PsrRequest $request, $isWebhook = false) {
        $inputData = $this->extractData($request);

        $profiles = $this->getActivatedVendorProfiles();

        if(!array_key_exists($vendorProfileKey, $profiles)) {
			throw new InvalidInputException("Vendor profile \"$vendorProfileKey\" not recognized.");
		}

        $profile = $profiles[$vendorProfileKey];

		$processorImpl = $this->getVendorProcessImplementation($profile);
		
		if(!$processorImpl->callbackIsAuthentic($inputData, $isWebhook)) {
			throw new InvalidInputException("Invalid callback intercepted. Callback claims to be originating from vendor profile \"$vendorProfileKey\".");
		}
        
		$paymentRequestIdentifiers = $processorImpl->extractPaymentCollectionRequestIdentifier($inputData, $isWebhook);
		
        $pcr = $this->dl->getPaymentRequest($paymentRequestIdentifiers); // $pcr -> payment collection request.

        if(empty($pcr)) {
            throw new InvalidInputException("Record of payment collection request not found.");
        }

        $lastInitState = $this->dl->getLastInitiatedState($pcr->getAlias());

        $chosenVendorProfileKey = $lastInitState->getChosenVendorProfile();

        if($chosenVendorProfileKey !== $vendorProfileKey) {
            throw new InvalidInputException("Invalid callback intercepted. Vendor switching is not allowed in this stage.");
        }

		return $pcr;
	}

    public function success($requestAlias, $parameters)
	{
        $this->dl->pushState($requestAlias, StateModel::STATE_SUCCESS, $parameters);
        return ResultModel::getMutatedInstance();
    }
    
    public function failed($requestAlias, $parameters)
	{
        $this->dl->pushState($requestAlias, StateModel::STATE_FAILED, $parameters);
        return ResultModel::getMutatedInstance();
    }

    public function setMock($key, $value)
    {
        if(!is_null($value)) {
            $this->mocks[$key] = $value;
            return;
        }

        unset($this->mocks[$key]);
    }

    public function getMock($key)
    {
        return isset($this->mocks[$key]) ? $this->mocks[$key] : null;
    }

    public function getCacher() {
        return $this->cacher;
    }
}
