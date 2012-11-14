<?php

namespace SilverStripe\BehatExtension\Context\ClassGuesser;

use Behat\Behat\Context\ClassGuesser\ClassGuesserInterface;

/**
 * Module context class guesser.
 * Provides module context class if found.
 */
class ModuleContextClassGuesser implements ClassGuesserInterface
{
    private $classSuffix;
    private $namespace;

    /**
     * Initializes guesser.
     *
     * @param string $classSuffix
     */
    public function __construct($classSuffix = 'Test\\Behaviour\\FeatureContext')
    {
        $this->classSuffix = $classSuffix;
    }

    /**
     * Sets bundle namespace to use for guessing.
     *
     * @param string $namespace
     */
    public function setModuleNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * Tries to guess context classname.
     *
     * @return string
     */
    public function guess()
    {
        // Try fully qualified namespace
        if (class_exists($class = $this->namespace.'\\'.$this->classSuffix)) {
            return $class;
        }
        // Fall back to namespace with SilverStripe prefix
        // TODO Remove once core has namespace capabilities for modules
        if (class_exists($class = 'SilverStripe\\'.$this->namespace.'\\'.$this->classSuffix)) {
            return $class;
        }
    }
}
