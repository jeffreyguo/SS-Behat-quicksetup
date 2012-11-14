<?php

namespace SilverStripe\BehatExtension\Console\Processor;

use Symfony\Component\DependencyInjection\ContainerInterface,
    Symfony\Component\Console\Command\Command,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface;

use Behat\Behat\Console\Processor\LocatorProcessor as BaseProcessor;

/**
 * Path locator processor.
 */
class LocatorProcessor extends BaseProcessor
{
    private $container;

    /**
     * Constructs processor.
     *
     * @param ContainerInterface $container Container instance
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Configures command to be able to process it later.
     *
     * @param Command $command
     */
    public function configure(Command $command)
    {
        $command->addArgument('features', InputArgument::OPTIONAL,
            "Feature(s) to run. Could be:".
            "\n- a dir (<comment>src/to/module/Features/</comment>), " .
            "\n- a feature (<comment>src/to/module/Features/*.feature</comment>), " .
            "\n- a scenario at specific line (<comment>src/to/module/Features/*.feature:10</comment>). " .
            "\n- Also, you can use short module notation (<comment>@moduleName/*.feature:10</comment>)"
        );
    }

    /**
     * Processes data from container and console input.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \RuntimeException
     */
    public function process(InputInterface $input, OutputInterface $output)
    {
        // Bootstrap SS so we can use module listing
        $frameworkPath = $this->container->getParameter('behat.silverstripe_extension.framework_path');
        $_GET['flush'] = 1;
        require_once $frameworkPath . '/core/Core.php';
        unset($_GET['flush']);

        $featuresPath = $input->getArgument('features');
        $pathSuffix   = $this->container->getParameter('behat.silverstripe_extension.context.path_suffix');

        $currentModuleName = null;
        $modules = \SS_ClassLoader::instance()->getManifest()->getModules();

        // get module specified in behat.yml
        $currentModuleName = $this->container->getParameter('behat.silverstripe_extension.module');

        // get module from short notation if path starts from @
        if ($featuresPath && preg_match('/^\@([^\/\\\\]+)(.*)$/', $featuresPath, $matches)) {
            $currentModuleName = $matches[1];
            // TODO Replace with proper module loader once AJShort's changes are merged into core
            $currentModulePath = $modules[$currentModuleName];
            $featuresPath = str_replace(
                '@'.$currentModuleName,
                $currentModulePath.DIRECTORY_SEPARATOR.$pathSuffix,
                $featuresPath
            );
        // get module from provided features path
        } elseif (!$currentModuleName && $featuresPath) {
            $path = realpath(preg_replace('/\.feature\:.*$/', '.feature', $featuresPath));
            foreach ($modules as $moduleName => $modulePath) {
                if (false !== strpos($path, realpath($modulePath))) {
                    $currentModuleName = $moduleName;
                    break;
                }
            }
        // if module is configured for profile and feature provided
        } elseif ($currentModuleName && $featuresPath) {
            $currentModulePath = $modules[$currentModuleName];
            $featuresPath = $currentModulePath.DIRECTORY_SEPARATOR.$pathSuffix.DIRECTORY_SEPARATOR.$featuresPath;
        }

        if ($currentModuleName) {
            $this->container
                ->get('behat.silverstripe_extension.context.class_guesser')
                ->setModuleNamespace(ucfirst($currentModuleName));
        }

        if (!$featuresPath) {
            $featuresPath = $this->container->getParameter('behat.paths.features');
        }

        $this->container
            ->get('behat.console.command')
            ->setFeaturesPaths($featuresPath ? array($featuresPath) : array());
    }
}
