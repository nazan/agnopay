<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\Exceptions\InvalidInputException;

class InputModel {
    protected $formKey;
    protected $fields;
    protected $options;

    public function __construct($formKey, $fields, $options) {
        $this->setFormKey($formKey);
        $this->setFields($fields);
        $this->setOptions($options);
    }

    public function getFormKey()
    {
        return $this->formKey;
    }

    public function setFormKey($formKey)
    {
        $this->formKey = $formKey;
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

    public function getOptions()
    {
        return $this->options;
    }

    public function setOptions($options)
    {
        if(!is_array($options)) {
            throw new InvalidInputException("Options for InputModel must be an associative array.");
        }

        foreach($options as $key => $option) {
            if(!isset($option['label']) || !is_scalar($option['label'])) {
                throw new InvalidInputException("Option '$key' does not specify label with key 'label'. Must be a human readable label string.");
            }

            if(!isset($option['params']) || !is_array($option['params'])) {
                throw new InvalidInputException("Option '$key' does not specify a list of query parameters in an HTTP URL.");
            }

            if(!isset($option['style']) || !is_scalar($option['style'])) {
                throw new InvalidInputException("Option '$key' does not specify style class value with key 'default'. CSS class.");
            }
        }

        $this->options = $options;
    }
}