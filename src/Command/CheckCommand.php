<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Command;

use Clesson\SilverStripeCodeGuard\Check\CheckContext;
use Clesson\SilverStripeCodeGuard\Check\CheckGroup;
use Clesson\SilverStripeCodeGuard\Check\CheckResult;
use Clesson\SilverStripeCodeGuard\Check\CheckRunner;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Clesson\SilverStripeCodeGuard\Check\Severity;
use Clesson\SilverStripeCodeGuard\Config\ConfigurationLoader;
use Clesson\SilverStripeCodeGuard\Project\ProjectDetector;
use Clesson\SilverStripeCodeGuard\Report\ConsoleReporter;
use Clesson\SilverStripeCodeGuard\Report\JsonReporter;
use Clesson\SilverStripeCodeGuard\Report\QualityReport;
use Clesson\SilverStripeCodeGuard\Support\GitIndexSnapshot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckCommand extends Command
{
    public function __construct(
        private readonly CheckRunner $runner,
        private readonly ConfigurationLoader $configurationLoader,
        private readonly ProjectDetector $projectDetector,
        private readonly ConsoleReporter $consoleReporter,
        private readonly JsonReporter $jsonReporter,
        private readonly GitIndexSnapshot $indexSnapshot,
        private readonly bool $gateMode = false,
    ) {
        parent::__construct($gateMode ? 'gate' : 'check');
    }

    protected function configure(): void
    {
        $this->setDescription($this->gateMode ? 'Run the configured release quality gate.' : 'Run project quality and security checks.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Project directory to inspect.', getcwd())
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Run only one check group.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Override detected project type (module or application).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (console or json).', 'console')
            ->addOption('staged', null, InputOption::VALUE_NONE, 'Inspect a temporary snapshot of the Git index instead of the working tree.')
            ->addOption('history', null, InputOption::VALUE_NONE, 'Also request a separate Gitleaks scan of Git history.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requestedPath = (string) $input->getOption('path');
        $projectPath = realpath($requestedPath);
        if ($projectPath === false || !is_dir($projectPath)) {
            $output->writeln('<error>Project path does not exist or is not a directory.</error>');

            return Command::INVALID;
        }
        $originalProjectPath = $projectPath;

        $group = $input->getOption('group');
        if ($group !== null && CheckGroup::tryFrom((string) $group) === null) {
            $output->writeln('<error>Unknown check group. Use: security, composer, documentation, files, code-quality, silverstripe, release.</error>');

            return Command::INVALID;
        }

        $typeOption = $input->getOption('type');
        if ($typeOption !== null && !in_array($typeOption, ['module', 'application'], true)) {
            $output->writeln('<error>Project type must be module or application.</error>');

            return Command::INVALID;
        }

        $format = (string) $input->getOption('format');
        if (!in_array($format, ['console', 'json'], true)) {
            $output->writeln('<error>Output format must be console or json.</error>');

            return Command::INVALID;
        }

        $snapshotPath = null;
        try {
            $scope = 'working tree (current files; Git history not included)';
            if ($input->getOption('staged')) {
                $snapshotPath = $this->indexSnapshot->create($projectPath);
                $projectPath = $snapshotPath;
                $scope = 'Git index snapshot (staged content only; commit history not included)';
            }
            if ($input->getOption('history')) {
                $scope .= '; Gitleaks scanned Git history separately';
            }

            $configuration = $this->configurationLoader->load($projectPath);
            $configuration['runtime']['history_enabled'] = (bool) $input->getOption('history');
            $configuration['runtime']['history_project_path'] = $originalProjectPath;
            $configuration['runtime']['staged'] = (bool) $input->getOption('staged');
            $configuration['runtime']['scan_scope'] = $input->getOption('staged')
                ? 'staged Git index snapshot'
                : 'current working tree';
            $configuredType = $typeOption ?? ($configuration['project']['type'] ?? null);
            $project = $this->projectDetector->detect($projectPath, is_string($configuredType) ? $configuredType : null);
            $context = new CheckContext($projectPath, $configuration, $project);
            $results = $this->runner->run($context, is_string($group) ? $group : null);

            if ($project->type === null && ($group === null || $group === 'release' || $group === 'silverstripe')) {
                $results[] = CheckResult::issue(
                    'Project type detection',
                    CheckGroup::RELEASE,
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'Could not determine whether this is a SilverStripe module or application.',
                    'composer.json',
                    suggestion: 'Add project.type: module or project.type: application to .codeguard.yml, or pass --type=module|application.',
                );
            }

            $report = new QualityReport($originalProjectPath, $project->name, $project->type, $results, $scope);
            $gate = $configuration['gate']['fail_on'] ?? ['error', 'high', 'critical'];
            if (!is_array($gate)) {
                $gate = ['error', 'high', 'critical'];
            }

            if ($format === 'json') {
                $output->writeln($this->jsonReporter->render($report, $this->gateMode, $gate));
            } else {
                $this->consoleReporter->render($report, $output, $this->gateMode, $gate);
            }

            if ($this->gateMode) {
                return $report->gatePassed($gate) ? Command::SUCCESS : Command::FAILURE;
            }
            foreach ($results as $result) {
                if (in_array($result->status, [CheckStatus::FAIL, CheckStatus::ERROR], true)) {
                    return Command::FAILURE;
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        } finally {
            if ($snapshotPath !== null) {
                $this->indexSnapshot->remove($snapshotPath);
            }
        }
    }
}
