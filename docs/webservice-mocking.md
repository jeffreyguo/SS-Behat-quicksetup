# Mocking SOAP Webservices with Phockito and Behat

## Overview

Web applications typically don't live in isolation: They provide data to third parties,
as well as consume data from other services, and often even write back to those services.
SOAP is a common web service layer to achieve this level of interaction.

Web services have a couple of disadvantages when it comes to testing though:

 - They're slow down our tests, responding in seconds rather than milliseconds
 - Their data can change over time, making it hard to start with a clean slate
 - They're not isolated, meaning multiple test runs accessing a webservice in parallel can cause side effects for each other
 - Since their data isn't defined in our tests, its hard to understand the assumptions and requirements of a test.

This is where mocks objects come in, which replace the "real"
webservice with a fake data defined in our test framework.
The challenge is to get mocks defined as in-memory objects via PHP
while executing Behat steps on the commandline, but applying those
same mocks in a different process when Behat/Mink/Selenium
perform a web request. That's solved by generated PHP code which is
included as part of the bootstrap process.

There's several parts to this:

 - [Behat](http://behat.org) parses features into executable steps
 - [Mink](http://mink.behat.org) interacts with [Selenium](http://selenium.googlecode.com) to remote control a browser
 - PHP's built-in `SoapClient` is used for the "real" API connection
 - A "gateway" class which encapsulates the SOAP interactions
 - [Phockito](https://github.com/hafriedlander/phockito) as a mocking framework to "hardcode" method returns
 - The `TestSessionStubCodeWriter` which writes PHP code to be included only in test runs.
 In our case it contains Phockito mock object definitions.

## Example: A Currency Conversion Rate Viewer

How the pieces fit together is best illustrated as an example.
We'll create a currency rate viewer,
based on a [free online webservice](http://www.webservicex.net/CurrencyConvertor.asmx?WSDL).
The example assumes you have a basic knowledge of [Behat](http://behat.org) and 
the [Behat SilverStripe extension](https://github.com/silverstripe-labs/silverstripe-behat-extension).
Let's explain the feature through the Gherkin language as Behat steps:

```feature
Feature:
	As a website visitor
	I want to see currency conversion rates
	In order to decide whether its worth buying

	Scenario: View conversion rates on homepage
		Given I have a currency rate from "NZD" to "EUR" of "1.56"
		And I go to "/convert?from=NZD&to=EUR"
		Then I should see "NZD -> EUR: 1.56"
```

Follow the [Behat+SilverStripe installation instructions](https://github.com/silverstripe-labs/silverstripe-behat-extension), then install the Phockito mocking framework:

```
composer require hafriedlander/phockito:*
```

First we'll create a `CurrencyGateway` class which encapsulates a `SoapClient`
through the [gateyway design pattern](http://martinfowler.com/eaaCatalog/gateway.html).
It wraps each SOAP method in its own method, reading and writing native PHP types such as arrays and integers. This decouples the business logic from the underlying service layer,
and allows us to mock the return values later without requiring access to the live webservice.

```php
// mysite/code/CurrencyGateway.php
class CurrencyGateway {
	protected $client;

	function __construct($client = null) {
		$this->client = new SoapClient('http://www.webservicex.net/CurrencyConvertor.asmx?WSDL');
	}

	function convert($from, $to) {
		return $this->client->ConversionRate($from, $to);
	}
}
```

The controller logic for this is really simple.
We'll stick to request parameters and plaintext responses just to keep the code
manageable, a more realistic controller would likely use a form and HTML formatted responses.
Its important that our `CurrencyGateway` is instanciated through the 
use of [dependency injection](http://doc.silverstripe.org/framework/en/trunk/reference/injector),
so we can replace its implementation with a mock object later.

```php
// mysite/code/MyController.php
class ConvertController extends Controller {
	function index($request) {
		$gateway = Injector::inst()->get('CurrencyGateway');
		$from = $request->getVar('from');
		$to = $request->getVar('to');
		$rate = $gateway->convert($from, $to);
		$this->response->addHeader('Content-Type', 'text/plain');
		return "$from -> $to: $rate";
	}
}
```

The controller needs to be hooked up to a route (in `mysite/_config/_config.yml`):

```yml
Director:
  rules:
    'convert/$Action': 'ConvertController'
```

If you haven't already, now's the time to initialize your Behat tests
through a call to `vendor/bin/behat --init @mysite`.
Copy the feature steps from above into a new `mysite/tests/behat/features/view-rates.feature` file.
Open the already generated `FeatureContext.php` file and add the following code.

```php
// mysite/tests/behat/features/bootstrap/Context/FeatureContext.php
class FeatureContext extends SilverStripeContext {

	protected $stubCodeWriter;

	public function __construct() {
		// ...

		$this->stubCodeWriter = Injector::inst()->get('TestSessionStubCodeWriter');
	}

	/**
	 * @BeforeScenario
	 */
	public function initTestSessionStubCode() {
		$php = <<<PHP
\$mock = Phockito::mock('CurrencyGateway');
Injector::inst()->registerService(\$mock, 'CurrencyGateway');
PHP;
		$this->stubCodeWriter->write($php);
	}

	/**
	 * @AfterScenario
	 */
	public function resetTestSessionStubCode() {
		$this->stubCodeWriter->reset();
	}

	public function getTestSessionState() {
		return array_merge(
			parent::getTestSessionState(),
			array('stubfile' => $this->stubCodeWriter->getFilePath())
		);
	}

	/**
	 * @Given /^I have a currency rate from "([^"]*)" to "([^"]*)" of "([^"]*)"$/
	 */
	public function stepGivenACurrency($from, $to, $rate) {
		$php = <<<PHP
Phockito::when(\$mock->convert('$from','$to'))->return($rate);
PHP;
		$writer->write($php);
	}
}
```

The `TestSessionStubCodeWriter` takes care of writing out PHP to a specified file.
It defaults to `testSessionStubCode.php` inside your webroot. The file only lives
for the duration of a test session, and is regenerated for each scenario to
avoid side effects (hence the methods tagged with `@BeforeScenario` and `@AfterScenario`).

A useful pattern here is to set up objects via `@BeforeScenario`, in our case
a mock gateway in `initTestSessionStubCode()`. This object can be used in later
step definitions like `stepGivenACurrency()` to mock webservice responses
without any further setup or duplication. 

The generated code which is executed on every web request reads:

```php
<?php
$mock = Phockito::mock('CurrencyGateway');
Injector::inst()->registerService($mock, 'CurrencyGateway');
Phockito::when($mock->convert('EUR','NZD'))->return(1.56);
```

Keep in mind escaping rules for PHP when placed in a heredoc block: 
Variables are resolved when the string is constructed, unless escaped with a backslash. 

The test session started in your browser by Selenium/Behat needs to know
which file to include, which is handled by the `getTestSessionState()` method.