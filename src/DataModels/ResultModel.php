<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\DataModels\StateModel;

use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;

class ResultModel {
    const TYPE_INPUT_COLLECTOR = 'input_collector';
    const TYPE_INPUT_COLLECTOR_REDIRECT = 'input_collector_redirect';
    const TYPE_COMPLETE = 'complete';
    const TYPE_FAILED = 'failed';
    const TYPE_MUTATED = 'mutated';
    const TYPE_FEEDBACK = 'feedback';

    protected $type;

    protected $input;

    protected $message;

    protected $options;

    public function __construct()
    {
        $this->input = null;
        $this->message = '';
        $this->options = null;
    }

    public function setAsInputCollector(InputModel $input)
    {
        $this->type = self::TYPE_INPUT_COLLECTOR;
        $this->input = $input;
    }

    public function setAsInputCollectorRedirect(RedirectInputModel $input)
    {
        $this->type = self::TYPE_INPUT_COLLECTOR_REDIRECT;
        $this->input = $input;
    }

    public function setAsComplete()
    {
        $this->type = self::TYPE_COMPLETE;
        $this->input = null;
    }

    public function setAsFailed()
    {
        $this->type = self::TYPE_FAILED;
        $this->input = null;
    }

    public function setAsMutated()
    {
        $this->type = self::TYPE_MUTATED;
        $this->input = null;
    }

    public function setAsFeedback(string $message, $options = null)
    {
        $this->type = self::TYPE_FEEDBACK;
        $this->input = null;
        $this->message = $message;
        $this->options = $options;
    }

    public static function getInputCollectorInstance($formKey, $fields, $options)
    {
        $inst = new self();
        $inst->setAsInputCollector(
            new InputModel($formKey, $fields, $options)
        );

        return $inst;
    }

    public static function getInputCollectorRedirectInstance($formKey, $fields, $options, $redirectUri)
    {
        $inst = new self();
        $inst->setAsInputCollectorRedirect(
            new RedirectInputModel($formKey, $fields, $options, $redirectUri)
        );

        return $inst;
    }

    public static function getCompleteInstance()
    {
        $inst = new self();
        $inst->setAsComplete();

        return $inst;
    }

    public static function getFailedInstance()
    {
        $inst = new self();
        $inst->setAsFailed();

        return $inst;
    }

    public static function getMutatedInstance()
    {
        $inst = new self();
        $inst->setAsMutated();

        return $inst;
    }

    public static function getFeedbackInstance(string $message, $options = null)
    {
        $inst = new self();
        $inst->setAsFeedback($message, $options);

        return $inst;
    }

    public static function getConclusionResultInstance(StateModel $stateModel = null)
    {
        if(!empty($stateModel)) {
            if($stateModel->getState() === StateModel::STATE_SUCCESS) {
                return self::getCompleteInstance();
            }
            
            if($stateModel->getState() === StateModel::STATE_FAILED) {
                return self::getFailedInstance();
            }
        }

        throw new InvalidMethodCallException("Request of given state object is not concluded. Unable to generate conclusion Result instance.");
    }

    public function getType()
    {
        return $this->type;
    }

    public function getInputModel()
    {
        return $this->input;
    }

    public function getMessage()
    {
        return $this->message;
    }
}