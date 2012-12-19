<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Step,
    Behat\Behat\Event\FeatureEvent,
    Behat\Behat\Event\ScenarioEvent,
    Behat\Behat\Event\SuiteEvent;
use Behat\Gherkin\Node\PyStringNode;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Mink\Driver\GoutteDriver,
    Behat\Mink\Driver\Selenium2Driver,
    Behat\Mink\Exception\UnsupportedDriverActionException;

use SilverStripe\BehatExtension\Context\SilverStripeAwareContextInterface;

use Symfony\Component\Yaml\Yaml;

// Mink etc.
require_once 'vendor/autoload.php';

/**
 * SilverStripeContext
 *
 * Generic context wrapper used as a base for Behat FeatureContext.
 */
class SilverStripeContext extends MinkContext implements SilverStripeAwareContextInterface
{
    protected $databaseName;

    /**
     * @var Array Partial string match for step names
     * that are considered to trigger Ajax request in the CMS,
     * and hence need special timeout handling.
     * @see \SilverStripe\BehatExtension\Context\BasicContext->handleAjaxBeforeStep().
     */
    protected $ajaxSteps;

    /**
     * @var Int Timeout in milliseconds, after which the interface assumes
     * that an Ajax request has timed out, and continues with assertions.
     */
    protected $ajaxTimeout;

    /**
     * @var String Relative URL to the SilverStripe admin interface.
     */
    protected $adminUrl;

    /**
     * @var String Relative URL to the SilverStripe login form.
     */
    protected $loginUrl;

    /**
     * @var String Relative path to a writeable folder where screenshots can be stored.
     * If set to NULL, no screenshots will be stored.
     */
    protected $screenshotPath;

    protected $context;
    protected $fixtures;
    protected $fixturesLazy;
    protected $filesPath;
    protected $createdFilesPaths;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param   array   $parameters     context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        // Initialize your context here
        $this->context = $parameters;
    }

    public function setDatabase($databaseName)
    {
        $this->databaseName = $databaseName;
    }

    public function setAjaxSteps($ajaxSteps)
    {
        if($ajaxSteps) $this->ajaxSteps = $ajaxSteps;
    }

    public function getAjaxSteps()
    {
        return $this->ajaxSteps;
    }

    public function setAjaxTimeout($ajaxTimeout)
    {
        $this->ajaxTimeout = $ajaxTimeout;
    }

    public function getAjaxTimeout()
    {
        return $this->ajaxTimeout;
    }

    public function setAdminUrl($adminUrl)
    {
        $this->adminUrl = $adminUrl;
    }

    public function getAdminUrl()
    {
        return $this->adminUrl;
    }

    public function setLoginUrl($loginUrl)
    {
        $this->loginUrl = $loginUrl;
    }

    public function getLoginUrl()
    {
        return $this->loginUrl;
    }

    public function setScreenshotPath($screenshotPath)
    {
        $this->screenshotPath = $screenshotPath;
    }

    public function getScreenshotPath()
    {
        return $this->screenshotPath;
    }

    public function getFixture($dataObject)
    {
        if (!array_key_exists($dataObject, $this->fixtures)) {
            throw new \OutOfBoundsException(sprintf('Data object `%s` does not exist!', $dataObject));
        }

        return $this->fixtures[$dataObject];
    }

    public function getFixtures()
    {
        return $this->fixtures;
    }

    /**
     * @BeforeScenario
     */
    public function before(ScenarioEvent $event)
    {
        if (!isset($this->databaseName)) {
            throw new \LogicException(
                'Context\'s $databaseName has to be set when implementing '
                . 'SilverStripeAwareContextInterface.'
            );
        }

        $url = $this->joinUrlParts($this->getBaseUrl(), '/dev/testsession/start');
        $params = array(
            'database' => $this->databaseName,
            'mailer' => 'SilverStripe\BehatExtension\Utility\TestMailer',
        );
        $url .= '?' . http_build_query($params);

        $this->getSession()->visit($url);
    }

    /**
     * @BeforeScenario @database-defaults
     */
    public function beforeDatabaseDefaults(ScenarioEvent $event)
    {
        \SapphireTest::empty_temp_db();
        \DB::getConn()->quiet();
        $dataClasses = \ClassInfo::subclassesFor('DataObject');
        array_shift($dataClasses);
        foreach ($dataClasses as $dataClass) {
            \singleton($dataClass)->requireDefaultRecords();
        }
    }

    /**
     * @AfterScenario @database-defaults
     */
    public function afterDatabaseDefaults(ScenarioEvent $event)
    {
        \SapphireTest::empty_temp_db();
    }

    /**
     * @AfterScenario @assets
     */
    public function afterResetAssets(ScenarioEvent $event)
    {
        if (is_array($this->createdFilesPaths)) {
            $createdFiles = array_reverse($this->createdFilesPaths);
            foreach ($createdFiles as $path) {
                if (is_dir($path)) {
                    \Filesystem::removeFolder($path);
                } else {
                    @unlink($path);
                }
            }
        }
        \SapphireTest::empty_temp_db();
    }

    /**
     * @Given /^there are the following ([^\s]*) records$/
     */
    public function thereAreTheFollowingRecords($dataObject, PyStringNode $string)
    {
        if (!is_array($this->fixtures)) {
            $this->fixtures = array();
        }
        if (!is_array($this->fixturesLazy)) {
            $this->fixturesLazy = array();
        }
        if (!isset($this->filesPath)) {
            $this->filesPath = realpath($this->getMinkParameter('files_path'));
        }
        if (!is_array($this->createdFilesPaths)) {
            $this->createdFilesPaths = array();
        }

        if (array_key_exists($dataObject, $this->fixtures)) {
            throw new \InvalidArgumentException(sprintf('Data object `%s` already exists!', $dataObject));
        }

        $fixture = array_merge(array($dataObject . ':'), $string->getLines());
        $fixture = implode("\n  ", $fixture);

        if ('Folder' === $dataObject) {
            $this->prepareTestAssetsDirectories($fixture);
        }

        if ('File' === $dataObject) {
            $this->prepareTestAssetsFiles($fixture);
        }

        $fixturesLazy = array($dataObject => array());
        if (preg_match('/=>(\w+)/', $fixture)) {
            $fixture_content = Yaml::parse($fixture);
            foreach ($fixture_content[$dataObject] as $identifier => &$fields) {
                foreach ($fields as $field_val) {
                    if (substr($field_val, 0, 2) == '=>') {
                        $fixturesLazy[$dataObject][$identifier] = $fixture_content[$dataObject][$identifier];
                        unset($fixture_content[$dataObject][$identifier]);
                    }
                }
            }
            $fixture = Yaml::dump($fixture_content);
        }

        // As we're dealing with split fixtures and can't join them, replace references by hand
//        if (preg_match('/=>(\w+)\.([\w.]+)/', $fixture, $matches)) {
//            if ($matches[1] !== $dataObject) {
//                $fixture = preg_replace_callback('/=>(\w+)\.([\w.]+)/', array($this, 'replaceFixtureReferences'), $fixture);
//            }
//        }
        $fixture = preg_replace_callback('/=>(\w+)\.([\w.]+)/', array($this, 'replaceFixtureReferences'), $fixture);
        // Save fixtures into database
        $this->fixtures[$dataObject] = new \YamlFixture($fixture);
        $model = \DataModel::inst();
        $this->fixtures[$dataObject]->saveIntoDatabase($model);
        // Lazy load fixtures into database
        // Loop is required for nested lazy fixtures
        foreach ($fixturesLazy[$dataObject] as $identifier => $fields) {
            $fixture = array(
                $dataObject => array(
                    $identifier => $fields,
                ),
            );
            $fixture = Yaml::dump($fixture);
            $fixture = preg_replace_callback('/=>(\w+)\.([\w.]+)/', array($this, 'replaceFixtureReferences'), $fixture);
            $this->fixturesLazy[$dataObject][$identifier] = new \YamlFixture($fixture);
            $this->fixturesLazy[$dataObject][$identifier]->saveIntoDatabase($model);
        }
    }

    protected function prepareTestAssetsDirectories($fixture)
    {
        $folders = Yaml::parse($fixture);
        foreach ($folders['Folder'] as $fields) {
            foreach ($fields as $field => $value) {
                if ('Filename' === $field) {
                    if (0 === strpos($value, 'assets/')) {
                        $value = substr($value, strlen('assets/'));
                    }

                    $folder_path = ASSETS_PATH . DIRECTORY_SEPARATOR . $value;
                    if (file_exists($folder_path) && !is_dir($folder_path)) {
                        throw new \Exception(sprintf('`%s` already exists and is not a directory', $this->filesPath));
                    }

                    \Filesystem::makeFolder($folder_path);
                    $this->createdFilesPaths[] = $folder_path;
                }
            }
        }
    }

    protected function prepareTestAssetsFiles($fixture)
    {
        $files = Yaml::parse($fixture);
        foreach ($files['File'] as $fields) {
            foreach ($fields as $field => $value) {
                if ('Filename' === $field) {
                    if (0 === strpos($value, 'assets/')) {
                        $value = substr($value, strlen('assets/'));
                    }

                    $filePath = $this->filesPath . DIRECTORY_SEPARATOR . basename($value);
                    if (!file_exists($filePath) || !is_file($filePath)) {
                        throw new \Exception(sprintf('`%s` does not exist or is not a file', $this->filesPath));
                    }
                    $asset_path = ASSETS_PATH . DIRECTORY_SEPARATOR . $value;
                    if (file_exists($asset_path) && !is_file($asset_path)) {
                        throw new \Exception(sprintf('`%s` already exists and is not a file', $this->filesPath));
                    }

                    if (!file_exists($asset_path)) {
                        if (@copy($filePath, $asset_path)) {
                            $this->createdFilesPaths[] = $asset_path;
                        }
                    }
                }
            }
        }
    }

    protected function replaceFixtureReferences($references)
    {
        if (!array_key_exists($references[1], $this->fixtures)) {
            throw new \OutOfBoundsException(sprintf('Data object `%s` does not exist!', $references[1]));
        }
        return $this->idFromFixture($references[1], $references[2]);
    }

    protected function idFromFixture($className, $identifier)
    {
        if (false !== ($id = $this->fixtures[$className]->idFromFixture($className, $identifier))) {
            return $id;
        }
        if (isset($this->fixturesLazy[$className], $this->fixturesLazy[$className][$identifier]) &&
                false !== ($id = $this->fixturesLazy[$className][$identifier]->idFromFixture($className, $identifier))) {
            return $id;
        }

        throw new \OutOfBoundsException(sprintf('`%s` identifier in Data object `%s` does not exist!', $identifier, $className));
    }

    /**
     * Parses given URL and returns its components
     *
     * @param $url
     * @return array|mixed Parsed URL
     */
    public function parseUrl($url)
    {
        $url = parse_url($url);
        $url['vars'] = array();
        if (!isset($url['fragment'])) {
            $url['fragment'] = null;
        }
        if (isset($url['query'])) {
            parse_str($url['query'], $url['vars']);
        }

        return $url;
    }

    /**
     * Checks whether current URL is close enough to the given URL.
     * Unless specified in $url, get vars will be ignored
     * Unless specified in $url, fragment identifiers will be ignored
     *
     * @param $url string URL to compare to current URL
     * @return boolean Returns true if the current URL is close enough to the given URL, false otherwise.
     */
    public function isCurrentUrlSimilarTo($url)
    {
        $current = $this->parseUrl($this->getSession()->getCurrentUrl());
        $test = $this->parseUrl($url);

        if ($current['path'] !== $test['path']) {
            return false;
        }

        if (isset($test['fragment']) && $current['fragment'] !== $test['fragment']) {
            return false;
        }

        foreach ($test['vars'] as $name => $value) {
            if (!isset($current['vars'][$name]) || $current['vars'][$name] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns base URL parameter set in MinkExtension.
     * It simplifies configuration by allowing to specify this parameter
     * once but makes code dependent on MinkExtension.
     *
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->getMinkParameter('base_url') ?: '';
    }

    /**
     * Joins URL parts into an URL using forward slash.
     * Forward slash usages are normalised to one between parts.
     * This method takes variable number of parameters.
     *
     * @param $...
     * @return string
     * @throws \InvalidArgumentException
     */
    public function joinUrlParts()
    {
        if (0 === func_num_args()) {
            throw new \InvalidArgumentException('Need at least one argument');
        }

        $parts = func_get_args();
        $trimSlashes = function(&$part) {
            $part = trim($part, '/');
        };
        array_walk($parts, $trimSlashes);

        return implode('/', $parts);
    }

    public function canIntercept()
    {
        $driver = $this->getSession()->getDriver();
        if ($driver instanceof GoutteDriver) {
            return true;
        }
        else {
            if ($driver instanceof Selenium2Driver) {
                return false;
            }
        }

        throw new UnsupportedDriverActionException('You need to tag the scenario with "@mink:goutte" or "@mink:symfony". Intercepting the redirections is not supported by %s', $driver);
    }

    /**
     * @Given /^(.*) without redirection$/
     */
    public function theRedirectionsAreIntercepted($step)
    {
        if ($this->canIntercept()) {
            $this->getSession()->getDriver()->getClient()->followRedirects(false);
        }

        return new Step\Given($step);
    }

    /**
     * @Given /^((?:I )fill in =>(.+?) for "([^"]*)")$/
     */
    public function iFillInFor($step, $reference, $field)
    {
        if (false === strpos($reference, '.')) {
            throw new \Exception('Fixture reference should be in following format: =>ClassName.identifier');
        }

        list($className, $identifier) = explode('.', $reference);
        $id = $this->idFromFixture($className, $identifier);
        //$step = preg_replace('#=>(.+?) for "([^"]*)"#', '"'.$id.'" for "'.$field.'"', $step);

        // below is not working, because Selenium can't interact with hidden inputs
        // return new Step\Given($step);

        // TODO: investigate how to simplify this and make universal
        $javascript = <<<JAVASCRIPT
if ('undefined' !== typeof window.jQuery) {
    window.jQuery('input[name="$field"]').val($id);
}
JAVASCRIPT;
        $this->getSession()->executeScript($javascript);
    }

    /**
     * @Given /^((?:I )fill in "([^"]*)" with =>(.+))$/
     */
    public function iFillInWith($step, $field, $reference)
    {
        if (false === strpos($reference, '.')) {
            throw new \Exception('Fixture reference should be in following format: =>ClassName.identifier');
        }

        list($className, $identifier) = explode('.', $reference);
        $id = $this->idFromFixture($className, $identifier);
        //$step = preg_replace('#"([^"]*)" with =>(.+)#', '"'.$field.'" with "'.$id.'"', $step);

        // below is not working, because Selenium can't interact with hidden inputs
        // return new Step\Given($step);

        // TODO: investigate how to simplify this and make universal
        $javascript = <<<JAVASCRIPT
if ('undefined' !== typeof window.jQuery) {
    window.jQuery('input[name="$field"]').val($id);
}
JAVASCRIPT;
        $this->getSession()->executeScript($javascript);
    }
}
