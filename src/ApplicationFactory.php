<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard;

use Clesson\SilverStripeCodeGuard\Check\CheckRegistry;
use Clesson\SilverStripeCodeGuard\Check\CheckRunner;
use Clesson\SilverStripeCodeGuard\Check\CodeQualityToolCheck;
use Clesson\SilverStripeCodeGuard\Check\ComposerAuditCheck;
use Clesson\SilverStripeCodeGuard\Check\ComposerLockPolicyCheck;
use Clesson\SilverStripeCodeGuard\Check\ComposerOutdatedCheck;
use Clesson\SilverStripeCodeGuard\Check\ComposerResolutionCheck;
use Clesson\SilverStripeCodeGuard\Check\ComposerValidationCheck;
use Clesson\SilverStripeCodeGuard\Check\DocumentationCheck;
use Clesson\SilverStripeCodeGuard\Check\ForbiddenFilesCheck;
use Clesson\SilverStripeCodeGuard\Check\GitHistoryCheck;
use Clesson\SilverStripeCodeGuard\Check\GitleaksCheck;
use Clesson\SilverStripeCodeGuard\Check\PersonalDataCheck;
use Clesson\SilverStripeCodeGuard\Check\ReleaseReadinessCheck;
use Clesson\SilverStripeCodeGuard\Check\SilverStripeCheck;
use Clesson\SilverStripeCodeGuard\Command\CheckCommand;
use Clesson\SilverStripeCodeGuard\Command\HookInstallCommand;
use Clesson\SilverStripeCodeGuard\Command\InitCommand;
use Clesson\SilverStripeCodeGuard\Config\ConfigurationLoader;
use Clesson\SilverStripeCodeGuard\Project\ProjectDetector;
use Clesson\SilverStripeCodeGuard\Report\ConsoleReporter;
use Clesson\SilverStripeCodeGuard\Report\JsonReporter;
use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Clesson\SilverStripeCodeGuard\Support\GitIndexSnapshot;
use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public static function create(): Application
    {
        $files = new ProjectFiles();
        $executables = new ExecutableLocator();
        $runner = new CheckRunner(new CheckRegistry([
            new ComposerValidationCheck($executables),
            new ComposerAuditCheck($executables),
            new ComposerLockPolicyCheck($files),
            new ComposerOutdatedCheck($executables),
            new ComposerResolutionCheck($executables),
            new DocumentationCheck($files),
            new ForbiddenFilesCheck($files),
            new GitleaksCheck($executables),
            new GitHistoryCheck($executables),
            new PersonalDataCheck($files),
            new CodeQualityToolCheck('phpstan', 'phpstan', $executables),
            new CodeQualityToolCheck('phpcs', 'phpcs', $executables),
            new CodeQualityToolCheck('phpunit', 'phpunit', $executables),
            new SilverStripeCheck($files),
            new ReleaseReadinessCheck($files),
        ]));
        $configuration = new ConfigurationLoader();
        $detector = new ProjectDetector();
        $reporter = new ConsoleReporter();
        $jsonReporter = new JsonReporter();
        $index = new GitIndexSnapshot();

        $application = new Application('SilverStripe CodeGuard', '0.1.0');
        $application->addCommands([
            new InitCommand(),
            new CheckCommand($runner, $configuration, $detector, $reporter, $jsonReporter, $index),
            new CheckCommand($runner, $configuration, $detector, $reporter, $jsonReporter, $index, true),
            new HookInstallCommand(),
        ]);

        return $application;
    }
}
