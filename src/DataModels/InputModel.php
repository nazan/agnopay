<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;

class InputModel {
    protected $fields;

    public function __construct($fields) {
        $this->setFields($fields);
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function setFields($fields)
    {
        if(!is_array($fields)) {
            throw new InvalidInputException("Fields for InputModel must be an associative array.");
        }

        foreach($fields as $key => $field) {
            if(!isset($field['label']) || empty($field['label'])) {
                throw new InvalidInputException("Field '$key' does not specify label with key 'label'. Must be a human readable label string.");
            }

            if(!isset($field['validation']) || empty($field['validation'])) {
                throw new InvalidInputException("Field '$key' does not specify validation with key 'validation'. Must be a pattern string or a array of possible values.");
            }

            if(!is_null($field['default']) && empty($field['default'])) {
                throw new InvalidInputException("Field '$key' does not specify default value with key 'default'. Must be null or a preset value.");
            }
        }

        $this->fields = $fields;
    }
}