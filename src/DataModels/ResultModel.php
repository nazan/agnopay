<?php

namespace SurfingCrab\AgnoPay\DataModels;

use SurfingCrab\AgnoPay\DataModels\StateModel;

use SurfingCrab\AgnoPay\Exceptions\InvalidMethodCallException;

class ResultModel {
    const TYPE_INPUT_COLLECTOR = 'input_collector';
    const TYPE_INPUT_COLLECTOR_REDIRECT = 'input_collector_redirect';
    const TYPE_COMPLETE = 'complete';
    const TYPE_FAILED = 'failed';
    const TYPE_FEEDBACK = 'feedback';

    protected $type;

    protected $input;

    protected $message;

    public function __construct()
    {
        $this->input = null;
        $this->message = '';
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

    public function setAsFeedback(string $message)
    {
        $this->type = self::TYPE_FEEDBACK;
        $this->input = null;
        $this->message = $message;
    }

    public static function getInputCollectorInstance($fields)
    {
        $inst = new self();
        $inst->setAsInputCollector(
            new InputModel($fields)
        );

        return $inst;
    }

    public static function getInputCollectorRedirectInstance($fields, $redirectUri)
    {
        $inst = new self();
        $inst->setAsInputCollectorRedirect(
            new RedirectInputModel($fields, $redirectUri)
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

    public static function getFeedbackInstance(string $message)
    {
        $inst = new self();
        $inst->setAsFailed($message);

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

        throw new InvalidMethodCallException("Reguest of given state object is not concluded. Unable to generate conclusion Result instance.");
    }

    public function getType()
    {
        return $this->type;
    }

    public function getInputModel()
    {
        return $this->input;
    }
}