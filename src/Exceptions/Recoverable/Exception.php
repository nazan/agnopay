<?php

namespace SurfingCrab\AgnoPay\Exceptions\Recoverable;

use SurfingCrab\AgnoPay\Exceptions\Exception as AgnoPayException;

class Exception extends AgnoPayException {
    protected $options;

    // sample $options.

    /*
    [
        ['href'=>'relative or absolute url', 'label'=>'button display name', 'style'=>'CSS classes for styling'],
    ]
    */

    public function __construct(string $message = "", int $code = 0, Throwable|null $previous = null, array $options = []) {
        parent::__construct($message, $code, $previous);

        $this->options = $options;
    }

    public function getOptions() {
        return $this->options;
    }
}
