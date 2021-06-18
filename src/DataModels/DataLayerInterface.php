<?php

namespace SurfingCrab\AgnoPay\DataModels;

interface DataLayerInterface {
    public function getVendorProfiles(): array;

    public function createPaymentRequest($vendorListString, $expiresIn, $amount, $currency, callable $postActions): RequestModel;

    public function getPaymentRequest($criteria, $sort = []): RequestModel;
    
    
    public function pushState($alias, $state, $parameters): StateModel;
    
    public function getLastInitiatedState($alias): StateModel;
    
    public function getCurrentState($alias): StateModel;

    public function getLastStateMatching($alias, array $conditions = []): StateModel;
}