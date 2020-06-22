<?php

namespace SurfingCrab\AgnoPay\Reference;

use PDO;
use PDOException;

use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;

use SurfingCrab\AgnoPay\DataModels\DataLayerInterface;

use SurfingCrab\AgnoPay\DataModels\RequestModel;
use SurfingCrab\AgnoPay\DataModels\StateModel;


class PdoSqliteDataLayer implements DataLayerInterface
{
    protected $dbh;

    public function __construct(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    public function getVendorProfiles(): array
    {
        return [
            'bmlconnect1' => [
                'label' => 'BML Connect 1',
                'vendor' => 'bmlconnect',
                'config' => [
                    'app_id' => 'abc',
                    'api_key' => 'def',
                    'env' => 'sandbox',
                ],
            ],
            'ooredoomobilemoney1' => [
                'label' => 'Ooredoo Mobile Money 1',
                'vendor' => 'ooredoomobilemoney',
                'config' => [
                    'url' => 'http://ooredoo.acme/process-not',
                    'merchant_id' => '1234',
                    'merchant_pin' => '1234',
                    'merchant_key' => '1234'
                ],
            ],
        ];
    }

    public function createPaymentRequest($vendorListString, $expiresIn, $amount, $currency): RequestModel
    {
        try {
            $sql = 'INSERT INTO `requests`(`vendor_profiles`, `expires_in`, `amount`, `currency`) VALUES(:vendor_profiles, :expires_in, :amount, :currency)';
            $stmt = $this->dbh->prepare($sql);
            $stmt->bindParam(':vendor_profiles', $vendorListString);
            $stmt->bindParam(':expires_in', $expiresIn);
            $stmt->bindParam(':amount', $amount);
            $stmt->bindParam(':currency', $currency);

            $stmt->execute();

            $requestId = $this->dbh->lastInsertId();

            $alias = hash('sha1', "$requestId");

            $sql = 'UPDATE `requests` SET `alias` = :alias WHERE `id` = :id';
            $stmt = $this->dbh->prepare($sql);
            $stmt->bindParam(':alias', $alias);
            $stmt->bindParam(':id', $requestId);

            $stmt->execute();
        } catch (PDOException $e) {
            throw $e;
        }

        $request = new RequestModel([
            'alias' => $alias,
            'vendor_profiles' => $vendorListString,
            'expires_in' => $expiresIn,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return $request;
    }

    public function getPaymentRequest($criteria, $sort = []): RequestModel
    {
        $values = [];
        if(is_string($criteria)) {
            $criteria = ['alias' => $criteria];
        }
        
        $conditions = [];
        if(is_array($criteria)) {
            foreach($criteria as $key => $value) {
                $values[":$key"] = $value;
                $conditions[] = "`$key` = :$key";
            }
        }

        $whereClause = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $orderFields = [];
        foreach($sort as $key => $value) {
            $orderFields[] = "`$key` $value";
        }

        $orderClause = !empty($orderFields) ? ' ORDER BY ' . implode(', ', $orderFields) : '';

        $stmt = $this->dbh->prepare("SELECT * FROM `requests`{$whereClause}{$orderClause}");

        $stmt->execute($values);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return new RequestModel($record);
    }

    public function pushState($alias, $state, $parameters): StateModel
    {
        $request = $this->getPaymentRequest($alias);

        if(!empty($parameters)) {
            $parameters = json_encode($parameters);
        }

        $attributes = $request->getRawAttributes();

        try {
            $sql = 'INSERT INTO `states`(`state`, `parameters`, `request_id`) VALUES(:state, :parameters, :request_id)';
            $stmt = $this->dbh->prepare($sql);
            $stmt->bindParam(':state', $state);
            $stmt->bindParam(':parameters', $parameters);
            $stmt->bindParam(':request_id', $attributes['id']);

            $stmt->execute();

            $stateId = $this->dbh->lastInsertId();
        } catch (PDOException $e) {
            throw $e;
        }

        $stmt = $this->dbh->prepare("SELECT * FROM `states` WHERE `id` = $stateId");

        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $queriedParameters = $this->extractParams($record);
        return new StateModel($request, $state, $record['created_at'], $queriedParameters);
    }

    public function getLastInitiatedState($alias): StateModel
    {
        $record = $this->getLastStateDataOf($alias, ['state' => StateModel::STATE_CLEARED]);

        if(!empty($record)) {
            throw new InvalidMethodCallException("Request uninitiated after clearing.");
        }

        $record = $this->getLastStateDataOf($alias, ['state' => StateModel::STATE_INIT]);

        if(empty($record)) {
            throw new InvalidMethodCallException("Request is uninitiated.");
        }

        $request = $this->getPaymentRequest($alias);
        $parameters = $this->extractParams($record);

        return new StateModel($request, $record['state'], $record['created_at'], $parameters);
    }

    public function getCurrentState($alias): StateModel
    {
        $record = $this->getLastStateDataOf($alias, []);

        if(empty($record)) {
            throw new InvalidMethodCallException("Request is uninitiated. No state record exist.");
        }

        $request = $this->getPaymentRequest($alias);
        $parameters = $this->extractParams($record);

        return new StateModel($request, $record['state'], $record['created_at'], $parameters);
    }

    public function getLastStateMatching($alias, array $conditions = []): StateModel
    {
        $record = $this->getLastStateDataOf($alias, $conditions);

        if(empty($record)) {
            throw new InvalidMethodCallException("Request state with given condition(s) does not exist.");
        }

        $request = $this->getPaymentRequest($alias);
        $parameters = $this->extractParams($record);

        return new StateModel($request, $record['state'], $record['created_at'], $parameters);
    }

    private function extractParams($record)
    {
        return isset($record['parameters']) && !empty($record['parameters']) ? json_decode($record['parameters'], true) : [];
    }

    private function getLastStateDataOf($alias, array $conditions) // WARGNING: values in $conditions are assumed to be sanitized.
    {
        $extraConditions = [];
        foreach($conditions as $column => $columnValue) {
            $extraConditions[] = "`s`.`$column` = '$columnValue'";
        }

        $extraConditions = !empty($extraConditions) ? ' AND ' . implode(' AND ', $extraConditions) : '';

        $sql = "SELECT `s`.`state` AS `state`, `s`.`parameters` AS `parameters`, `s`.`created_at` AS `created_at` FROM `states` `s` INNER JOIN `requests` `r` ON `s`.`request_id` = `r`.`id` WHERE `r`.`alias` = :alias{$extraConditions} ORDER BY `s`.`id` DESC LIMIT 1";

        $stmt = $this->dbh->prepare($sql);

        $stmt->execute([':alias' => $alias]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}