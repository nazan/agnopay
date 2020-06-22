<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;
use SurfingCrab\AgnoPay\Exceptions\CorruptDataException;

class StateModel {
    protected $request;

    protected $state;

    protected $createdAt;

    protected $parameters;

    const STATE_CLEARED = 'cleared'; // This means reset to new.
    const STATE_INIT = 'initiated';
    const STATE_SUCCESS = 'success';
    const STATE_FAILED = 'failed';

    public function __construct(RequestModel $request, $state, $createdAt, $parameters = [])
    {
        $this->request = $request;
        $this->state = $state;
        $this->createdAt = \DateTime::createFromFormat('Y-m-d H:i:s', $createdAt);
        $this->setParameters($parameters);
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function getState()
    {
        return $this->state;
    }

    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function setParameters($parameters)
    {
        $this->parameters = $parameters;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    public function getInitiatedTime()
    {
        if($this->state !== self::STATE_INIT) {
            throw new InvalidMethodCallException("Only StateModel instances in 'init' state has an initiated time attribute.");
        }

        if(!isset($this->parameters) || !is_array($this->parameters) || !array_key_exists('initiated_at', $this->parameters)) {
            throw new CorruptDataException("StateModel parameter 'initiated_at' does not exist.");
        }
        
        try {
            return \DateTime::createFromFormat('Y-m-d H:i:s', $this->parameters['initiated_at']);
        } catch(\Exception $excp) {
            throw new CorruptDataException("StateModel parameter 'initiated_at' is in an unexpected format.");
        }
    }

    public function getChosenVendorProfile()
    {
        if($this->state !== self::STATE_INIT) {
            throw new InvalidMethodCallException("Only StateModel instances in 'init' state has a chosen vendor profile attribute.");
        }

        if(!isset($this->parameters) || !is_array($this->parameters) || !array_key_exists('vendor_profile', $this->parameters)) {
            throw new CorruptDataException("StateModel parameter 'vendor_profile' does not exist.");
        }
        
        return $this->parameters['vendor_profile'];
    }
}