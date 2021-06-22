<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;

class RedirectInputModel extends InputModel {
    protected $redirectUri;

    public function __construct($formKey, $fields, $options, $redirectUri) {
        parent::__construct($formKey, $fields, []);
        $this->setRedirectUri($redirectUri);
    }

    public function getRedirectUri()
    {
        return $this->redirectUri;
    }

    public function setRedirectUri($redirectUri)
    {
        if(!is_string($redirectUri)) {
            throw new InvalidInputException("Redirect URI for InputModel must be an absolute URI string.");
        }

        $this->redirectUri = $redirectUri;
    }
}