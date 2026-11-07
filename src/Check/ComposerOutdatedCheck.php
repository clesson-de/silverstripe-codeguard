<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Symfony\Component\Process\Process;

final class ComposerOutdatedCheck implements QualityCheckInterface
{
    public function __construct(private readonly ExecutableLocator $executables)
    {
    }

    public function getName(): string
    {
        return 'Outdated direct Composer dependencies';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::COMPOSER;
    }

    public function run(CheckContext $context): array
    {
        $configuration = $context->checkConfiguration('composer');
        if (!$context->isEnabled('composer') || ($configuration['outdated'] ?? false) !== true) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::SKIP,
                Severity::INFO,
                'Outdated dependency checks are disabled; enable checks.composer.outdated to run the network-backed Composer query.',
            )];
        }
        $composer = $this->executables->find('composer', $context->projectPath);
        if ($composer === null) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'Composer is unavailable; direct dependency update status was not checked.',
            )];
        }

        $process = new Process([$composer, 'outdated', '--direct', '--no-interaction', '--format=json'], $context->projectPath, timeout: 180);
        $process->run();
        if (!$process->isSuccessful()) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'Composer could not query direct dependency update status: ' . substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 1000),
                suggestion: 'Check Composer connectivity and lock-file consistency. This optional check does not install or update dependencies.',
            )];
        }

        try {
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'Composer returned invalid JSON for its outdated-dependency report: ' . $exception->getMessage(),
            )];
        }

        $installed = $report['installed'] ?? [];
        $results = [];
        if (is_array($installed)) {
            foreach ($installed as $dependency) {
                if (!is_array($dependency) || !isset($dependency['name'], $dependency['version'], $dependency['latest'])) {
                    continue;
                }
                if ((string) $dependency['version'] === (string) $dependency['latest']) {
                    continue;
                }
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::WARN,
                    Severity::WARNING,
                    sprintf(
                        'Direct dependency %s has a newer reported version (%s to %s).',
                        $dependency['name'],
                        $dependency['version'],
                        $dependency['latest'],
                    ),
                    'composer.lock',
                    suggestion: 'Review the update against supported SilverStripe and PHP constraints before changing the lock file.',
                );
            }
        }

        return $results !== []
            ? $results
            : [CheckResult::pass($this->getName(), $this->getGroup(), 'No direct dependency updates were reported within declared constraints.')];
    }
}
