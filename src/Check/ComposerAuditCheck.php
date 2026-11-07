<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Symfony\Component\Process\Process;

final class ComposerAuditCheck implements QualityCheckInterface
{
    public function __construct(private readonly ExecutableLocator $executables)
    {
    }

    public function getName(): string
    {
        return 'Composer dependency audit';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::COMPOSER;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('composer')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Composer checks are disabled by configuration.')];
        }

        $configuration = $context->checkConfiguration('composer');
        if (($configuration['audit'] ?? true) !== true) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::SKIP,
                Severity::INFO,
                'Composer audit is disabled by configuration.',
            )];
        }
        if (!is_file($context->projectPath . '/composer.lock')) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'Dependency audit was not run because composer.lock is missing.',
                'composer.lock',
                suggestion: 'Commit composer.lock for applications, or run the audit after resolving module dependencies.',
            )];
        }

        $composer = $this->executables->find('composer', $context->projectPath);
        if ($composer === null) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'Composer is unavailable; dependency vulnerabilities could not be checked.',
                suggestion: 'Install Composer in the CI environment and rerun the audit.',
            )];
        }

        $process = new Process([$composer, 'audit', '--no-interaction', '--format=plain'], $context->projectPath, timeout: 120);
        $process->run();
        if (!$process->isSuccessful()) {
            $findingsDetected = $process->getExitCode() === 1;
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                $findingsDetected ? CheckStatus::FAIL : CheckStatus::ERROR,
                $findingsDetected ? Severity::HIGH : Severity::ERROR,
                $findingsDetected
                    ? 'Composer reported vulnerable or abandoned dependencies: ' . trim($process->getErrorOutput() ?: $process->getOutput())
                    : 'Composer could not complete the dependency audit: ' . trim($process->getErrorOutput() ?: $process->getOutput()),
                'composer.lock',
                suggestion: $findingsDetected
                    ? 'Review Composer audit findings and update within the project’s supported version constraints.'
                    : 'Check the Composer version and registry connectivity, then rerun the audit.',
            )];
        }

        return [CheckResult::pass($this->getName(), $this->getGroup(), 'Composer reported no known advisories for the locked dependencies.')];
    }
}
