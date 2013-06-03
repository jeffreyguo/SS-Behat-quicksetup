<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Context\Step,
    Behat\Behat\Event\StepEvent,
    Behat\Behat\Exception\PendingException;
use Behat\Mink\Driver\Selenium2Driver;
use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode;

// PHPUnit
require_once 'PHPUnit/Autoload.php';
require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Context used to create fixtures in the SilverStripe ORM.
 */
class FixtureContext extends BehatContext
{
    protected $context;

    /**
     * @var \FixtureFactory
     */
    protected $fixtureFactory;

    protected $filesPath;

    protected $createdFilesPaths;

    public function __construct(array $parameters)
    {
        $this->context = $parameters;
    }

    public function getSession($name = null)
    {
        return $this->getMainContext()->getSession($name);
    }

    /**
     * @return \FixtureFactory
     */
    public function getFixtureFactory() {
        if(!$this->fixtureFactory) {
            $this->fixtureFactory = \Injector::inst()->create('FixtureFactory', 'FixtureContextFactory');    
        }
        return $this->fixtureFactory;
    }

    /**
     * @param \FixtureFactory $factory
     */
    public function setFixtureFactory(\FixtureFactory $factory) {
        $this->fixtureFactory = $factory;
    }

    /**
     * Example: Given a page "Page 1"
     * 
     * @Given /^(?:(an|a|the) )(?<type>[^"]+)"(?<id>[^"]+)"$/
     */
    public function stepCreateRecord($type, $id)
    {
        $class = $this->convertTypeToClass($type);
        $this->fixtureFactory->createObject($class, $id);
    }
   
    /**
     * Example: Given a page "Page 1" with "URL"="page-1" and "Content"="my page 1" 
     * 
     * @Given /^(?:(an|a|the) )(?<type>[^"]+)"(?<id>[^"]+)" with (?<data>.*)$/
     */
    public function stepCreateRecordWithData($type, $id, $data)
    {
        $class = $this->convertTypeToClass($type);
        preg_match_all(
            '/"(?<key>[^"]+)"\s*=\s*"(?<value>[^"]+)"/', 
            $data,
            $matches
        );
        $fields = $this->convertFields(
            $class,
            array_combine($matches['key'], $matches['value'])
        );
        $this->fixtureFactory->createObject($class, $id, $fields);
    }

    /**
     * Example: And the page "Page 2" has the following data 
     * | Content | <blink> |
     * | My Property | foo |
     * | My Boolean | bar |
     * 
     * @Given /^(?:(an|a|the) )(?<type>[^"]+)"(?<id>[^"]+)" has the following data$/
     */
    public function stepCreateRecordWithTable($type, $id, $null, TableNode $fieldsTable)
    {
        $class = $this->convertTypeToClass($type);
        // TODO Support more than one record
        $fields = $this->convertFields($class, $fieldsTable->getRowsHash());
        $this->fixtureFactory->createObject($class, $id, $fields);
    }

    /**
     * Example: Given the page "Page 1.1" is a child of the page "Page1" 
     * 
     * @Given /^(?:(an|a|the) )(?<type>[^"]+)"(?<id>[^"]+)" is a (?<relation>[^\s]*) of (?:(an|a|the) )(?<relationType>[^"]+)"(?<relationId>[^"]+)"/
     */
    public function stepUpdateRecordRelation($type, $id, $relation, $relationType, $relationId)
    {
        $class = $this->convertTypeToClass($type);
        $relationClass = $this->convertTypeToClass($relationType);
        
        $obj = $this->fixtureFactory->get($class, $id);
        if(!$obj) $obj = $this->fixtureFactory->createObject($class, $id);
        
        $relationObj = $this->fixtureFactory->get($relationClass, $relationId);
        if(!$relationObj) $relationObj = $this->fixtureFactory->createObject($relationClass, $relationId);
        
        switch($relation) {
            case 'parent':
                $relationObj->ParentID = $obj->ID;
                $relationObj->write();
                break;
            case 'child':
                $obj->ParentID = $relationObj->ID;
                $obj->write();
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Invalid relation "%s"', $relation
                ));
        }
    }

    /**
     * Example: Given the page "Page 1" is not published 
     * 
     * @Given /^(?:(an|a|the) )(?<type>[^"]+)"(?<id>[^"]+)" is (?<state>[^"]*)$/
     */
    public function stepUpdateRecordState($type, $id, $state)
    {
        $class = $this->convertTypeToClass($type);
        $obj = $this->fixtureFactory->get($class, $id);
        if(!$obj) {
            throw new \InvalidArgumentException(sprintf(
                'Can not find record "%s" with identifier "%s"',
                $type,
                $id
            ));
        }

        switch($state) {
            case 'published':
                $obj->publish('Stage', 'Live');
                break;
            case 'not published':
            case 'unpublished':
                $oldMode = \Versioned::get_reading_mode();
                \Versioned::reading_stage('Live');
                $clone = clone $obj;
                $clone->delete();
                \Versioned::reading_stage($oldMode);
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Invalid state: "%s"', $state
                ));    
        }
    }

    /**
     * Converts a natural language class description to an actual class name.
     * Respects {@link DataObject::$singular_name} variations.
     * Example: "redirector page" -> "RedirectorPage"
     * 
     * @param String 
     * @return String Class name
     */
    protected function convertTypeToClass($type) 
    {
        $type = trim($type);

        // Try direct mapping
        $class = str_replace(' ', '', ucfirst($type));
        if(class_exists($class) || !is_subclass_of($class, 'DataObject')) {
            return $class;
        }

        // Fall back to singular names
        foreach(array_values(\ClassInfo::subclassesFor('DataObject')) as $candidate) {
            if(singleton($candidate)->singular_name() == $type) return $candidate;
        }

        throw new \InvalidArgumentException(sprintf(
            'Class "%s" does not exist, or is not a subclass of DataObjet',
            $class
        ));
    }

    /**
     * Updates an object with values, resolving aliases set through
     * {@link DataObject->fieldLabels()}.
     * 
     * @param String Class name
     * @param Array Map of field names or aliases to their values.
     * @return Array Map of actual object properties to their values.
     */
    protected function convertFields($class, $fields) {
        $labels = singleton($class)->fieldLabels();
        foreach($fields as $fieldName => $fieldVal) {
            if(array_key_exists($fieldName, $labels)) {
                unset($fields[$fieldName]);
                $fields[$labels[$fieldName]] = $fieldVal;
                
            }
        }
        return $fields;
    }
   
}
