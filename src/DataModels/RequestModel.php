<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\Exceptions\MissingDataException;

class RequestModel {
    protected $attributes;

    public function __construct($attributes)
    {
        $this->setRawAttributes($attributes);
    }

    public function setRawAttributes($attributes)
    {
        foreach($attributes as $key => $value) {
            if(is_scalar($value)) {
                $this->attributes[$key] = $value;
            }
        }
    }

    public function getRawAttributes()
    {
        return $this->attributes;
    }

    public function getAlias()
    {
        if(isset($this->attributes['alias']) && is_string($this->attributes['alias'])) {
            return $this->attributes['alias'];
        }

        throw new MissingDataException("Field 'alias' is required.");
    }

    public function getVendorProfiles()
    {
        if(isset($this->attributes['vendor_profiles']) && is_string($this->attributes['vendor_profiles'])) {
            return preg_split('/[\s,:;]+/', $this->attributes['vendor_profiles']);
        }

        throw new MissingDataException("Field 'vendor_profiles' is required.");
    }

    public function getExpiresIn()
    {
        if(isset($this->attributes['expires_in']) && is_numeric($this->attributes['expires_in'])) {
            return $this->attributes['expires_in'];
        }

        throw new MissingDataException("Field 'expires_in' is required.");
    }

    public function getAmount()
    {
        if(isset($this->attributes['amount']) && is_numeric($this->attributes['amount'])) {
            return intval($this->attributes['amount']);
        }

        throw new MissingDataException("Field 'amount' is required.");
    }

    public function getCurrency()
    {
        if(isset($this->attributes['currency']) && is_string($this->attributes['currency'])) {
            return $this->attributes['currency'];
        }

        throw new MissingDataException("Field 'currency' is required.");
    }

    public function toArray(): array
    {
        return [
            'alias' => $this->getAlias(),
            'vendor_profiles' => $this->getVendorProfiles(),
            'expires_in' => $this->getExpiresIn(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
        ];
    }

    public function getDataForPersistence(): array
    {
        $asArray = $this->toArray();

        $asArray['vendor_profiles'] = implode(',', $asArray['vendor_profiles']);
        $asArray['expires_in'] = $asArray['expires_in']->format('Y-m-d H:i:s');

        return $asArray;
    }
}