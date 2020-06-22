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

To gain more understanding, refer to the following test cases in class 'ServiceTest' under 'tests' folder.

- testBmlProceed()
- testOoredooProceed()

## Get involved

Docker development environment for this project is provided in the following Github repository.

[Docker dev environment](https://github.com/nazan/agnopay-dev.git)