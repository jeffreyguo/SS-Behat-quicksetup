<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
Behat\Behat\Context\TranslatedContextInterface,
Behat\Behat\Context\BehatContext,
Behat\Behat\Context\Step,
Behat\Behat\Event\FeatureEvent,
Behat\Behat\Event\ScenarioEvent,
Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
Behat\Gherkin\Node\TableNode;
use Symfony\Component\DomCrawler\Crawler;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Context used to define steps related to email sending.
 */
class EmailContext extends BehatContext
{
    protected $context;

    protected $mailer;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        // Initialize your context here
        $this->context = $parameters;
    }

    /**
     * Get Mink session from MinkContext
     */
    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @BeforeScenario
     */
    public function before(ScenarioEvent $event)
    {
        // Also set through the 'supportbehat' extension
        // to ensure its available both in CLI execution and the tested browser session
        $this->mailer = new \SilverStripe\BehatExtension\Utility\TestMailer();
        \Email::set_mailer($this->mailer);
        \Config::inst()->update("Email","send_all_emails_to", null);
    }

    /**
     * @Given /^there should be an email (to|from) "([^"]*)"$/
     */
    public function thereIsAnEmailFromTo($direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        assertNotNull($match);
    }

    /**
     * @Given /^there should be an email (to|from) "([^"]*)" titled "([^"]*)"$/
     */
    public function thereIsAnEmailFromToTitled($direction, $email, $subject)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from, $subject);
        assertNotNull($match);
    }

    /**
     * @When /^I click on the "([^"]*)" link in the email (to|from) "([^"]*)"$/
     */
    public function iGoToInTheEmailTo($linkSelector, $direction, $email)
    {
        $to = ($direction == 'to') ? $email : null;
        $from = ($direction == 'from') ? $email : null;
        $match = $this->mailer->findEmail($to, $from);
        assertNotNull($match);

        $crawler = new Crawler($match['Content']);
        $linkEl = $crawler->selectLink($linkSelector);
        assertNotNull($linkEl);
        $link = $linkEl->attr('href');
        assertNotNull($link);

        return new Step\When(sprintf('I go to "%s"', $link));
    }

    /**
     * @Given /^I clear all emails$/
     */
    public function iClearAllEmails()
    {
        return $this->mailer->clearEmails();
    }
}
