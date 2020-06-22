# Agno Pay

Online payment collection framework/library. This libary saves you from implementing the steps required to collect a payment via any of the payment providers listed [below](#implemented-payment-providers).

## Implemented Payment Providers

- Bank of Maldives Connect
- Ooredoo Mobile Money (M-Faisa)

## How to setup

### Step 1

Implement the interface 'SurfingCrab\AgnoPay\DataModels\DataLayerInterface'. A sample PDO based implementation is provided for reference in 'SurfingCrab\AgnoPay\Reference\PdoSqliteDataLayer'. Note that the name contains 'Sqlite' by mistake, this will be corrected to 'MySql' in a future version.

### Step 2

Build an instance of main service class 'SurfingCrab\AgnoPay\Service'. For an example of how to do that, refer to 'setUp()' method of 'ServiceTest' class under 'tests' folder.

Once the service instance is created store it away in a place where it can be retrieved with ease, perhaps in an IOC container included in your framework of choice.

## How to use

The basic idea is that, payment collection process is always a list of steps that must be carried out sequentially. Whenever the 'proceed(...)' method of main service instance is called, it will try to carry out the next action in the flow or prompt the caller for more input. So, one of two things happen, it asks for more input or proceed to next step. Therefore the user of this library must continuously call the 'proceed(...)' method until a 'ResultModel` instance of type 'ResultModel::TYPE_COMPLETE' is returned.

Note that for user input, the service class accepts a Psr7 based HTTP request instance. Admittedly, it is a bit awkward to build an HTTP request instance every time input needs to be fed. This design decision was made because, more often than not this library is expected to be used underneath some sort of HTTP layer. Meaning, users of this library will not have to build the HTTP request instance manually, instead their framework of choice will do it automatically for them. After all, online payments are collected over the web, therefore the former is a fair assumption.

Basic flow of collecting a payment is as follows.

- Create a new payment request and get a handle to it. Payment requests are modeled with class 'SurfingCrab\AgnoPay\DataModels\RequestModel`. A payment request's identity can be had with 'getAlias()' method. Note that this alias identity is used through out this library to pass around a payment request object.

- Call the 'proceed(RequestModel $newRequest, PsrRequest $input)' method of main service class which was built in 'Step 2' above. This call will always respond with an instance of 'SurfingCrab\AgnoPay\DataModels\ResultModel', which indicates one of several states. This method must be called repeatedly until all the steps are completed and a 'ResultModel' instance of type 'ResultModel::TYPE_COMPLETED' is returned.

### Example usage scenarios

**IMPORTANT:** Functions/methods prefixed with double underscores '__' does not exist. They are meant as pseudocode to clarify the process.


#### Create a new payment collection request

```PHP
use SurfingCrab\AgnoPay\Service as AgnoPayService;
use SurfingCrab\AgnoPay\Reference\PdoSqliteDataLayer;

// Create the main service instance.
$service = new AgnoPayService(new PdoSqliteDataLayer(new \PDO("mysql:host=example;dbname=example", 'user', 'password')));

/*
First, enable the payment service providers that you need.
From the supported ones, a subset can also be activated.
*/
$service->activateAllVendorProfiles();

// Create a new payment collection request by specifying amount and currency.
$subject = $service->create(1.99, 'MVR');
```

#### Collect a payment

```PHP
use SurfingCrab\AgnoPay\Service as AgnoPayService;
use SurfingCrab\AgnoPay\Reference\PdoSqliteDataLayer;
use SurfingCrab\AgnoPay\DataModels\ResultModel;
use SurfingCrab\AgnoPay\Exceptions\Excption as MyException;

// Create the main service instance.
$service = new AgnoPayService(new PdoSqliteDataLayer(new \PDO("mysql:host=example;dbname=example", 'user', 'password')));

$alias = __getAliasFromUserInput();

// Retrieve the payment collection request object.
$subject = $service->get($alias);

try {
    if($userInput = __userHasProvidedInput()) {
        $result = $service->proceed($subject->getAlias(), $userInput);
    } else {
        $result = $service->proceed($subject->getAlias());
    }

    if($result->getType() === ResultModel::TYPE_FAILED) {
        // Show failed message to user and end.
        return __buildResponse("Failed with: {$result->getMessage()}");
    }

    if($result->getType() == ResultModel::TYPE_FEEDBACK) {
        $feedbackMessage = $result->getMessage();

        // Return with feedback message to user.
        return __buildResponse($feedbackMessage);
    }

    if($result->getType() == ResultModel::TYPE_INPUT_COLLECTOR) {
        $inputForm = __makeInputForm($result->getInputModel());
        
        // Return with form for user input collection.
        return __buildResponse($inputForm);
    }

    if($result->getType() == ResultModel::TYPE_INPUT_COLLECTOR_REDIRECT) {
        $inputFormOrRedirectTargetLink = __makeRedirectFormOrLink($result->getInputModel());
        
        // Redirect user or return with post form with external URI.
        return __buildResponse($inputFormOrRedirectTargetLink);
    }
} catch(MyException $excp) {
    /*
    This indicates an error condition.
    The payment request will be aborted and the service will not allow further proceedings.
    End-user must be notified of this error.
    */
    throw $excp;
}

// Show success message to user and end.
return __buildResponse("Success");
```


To gain more understanding, refer to the following test cases in class 'ServiceTest' under 'tests' folder.

- testBmlProceed()
- testOoredooProceed()

## Get involved

Docker development environment for this project is provided in the following Github repository.

[Docker dev environment](https://github.com/nazan/agnopay-dev.git)