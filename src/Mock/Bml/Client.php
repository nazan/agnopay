<?php

namespace SurfingCrab\AgnoPay\Mock\Bml;

class Client {
    public $transactions;

    public function __construct()
    {
        $this->transactions = new Transactions();
    }
}