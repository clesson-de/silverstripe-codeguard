<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Symfony\Component\Process\Process;

final class ComposerResolutionCheck implements QualityCheckInterface
{
    public function __construct(private readonly ExecutableLocator $executables)
    {
    }

    public function getName(): string
    {
        return 'Composer dependency resolution';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::COMPOSER;
    }

    public function run(CheckContext $context): array
    {
        $configuration = $context->checkConfiguration('composer');
        if (!$context->isEnabled('composer') || ($configuration['resolve'] ?? false) !== true) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::SKIP,
                Severity::INFO,
                'Dependency resolution is disabled; enable checks.composer.resolve for the network-backed, non-mutating dry run.',
            )];
        }

        $composer = $this->executables->find('composer', $context->projectPath);
        if ($composer === null) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'Composer is unavailable; dependency installability was not checked.',
            )];
        }

        $process = new Process(
            [$composer, 'install', '--dry-run', '--no-interaction', '--no-scripts', '--no-plugins'],
            $context->projectPath,
            timeout: 300,
        );
        $process->run();
        if (!$process->isSuccessful()) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'Composer could not resolve the declared dependencies: ' . substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 2000),
                'composer.json',
                suggestion: 'Review version constraints and platform requirements. This dry run disables scripts and plugins and does not install dependencies.',
            )];
        }

        return [CheckResult::pass(
            $this->getName(),
            $this->getGroup(),
            'Composer resolved the declared dependencies in a dry run with scripts and plugins disabled.',
        )];
    }
}
