<?php

namespace SurfingCrab\AgnoPay\Mock\Bml;

class Transactions {
    protected $store = [];

    public function create($parameters)
    {
        $transaction = new \stdClass();
        $transaction->id = hash('sha1', (new \DateTime())->format('Y-m-d H:i:s'));
        $transaction->url = "http://example.com/{$transaction->id}";

        foreach($parameters as $key => $value) {
            $transaction->$key = $value;
        }

        $transaction->state = 'confirmed';

        return $this->store[$transaction->id] = $transaction;
    }

    public function get($id)
    {
        return $this->store[$id];
    }

    public function getStore()
    {
        return $this->store;
    }
}