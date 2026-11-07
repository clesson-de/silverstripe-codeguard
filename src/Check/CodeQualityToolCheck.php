<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Symfony\Component\Process\Process;

final class CodeQualityToolCheck implements QualityCheckInterface
{
    public function __construct(
        private readonly string $tool,
        private readonly string $executableName,
        private readonly ExecutableLocator $executables,
    ) {
    }

    public function getName(): string
    {
        return strtoupper($this->tool) . ' analysis';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::CODE_QUALITY;
    }

    public function run(CheckContext $context): array
    {
        $configuration = $context->checkConfiguration('code_quality')[$this->tool] ?? [];
        if (!is_array($configuration) || ($configuration['enabled'] ?? false) !== true) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::SKIP,
                Severity::INFO,
                strtoupper($this->tool) . ' execution is disabled; enable it explicitly in .codeguard.yml.',
            )];
        }

        $executable = $this->executables->find($this->executableName, $context->projectPath);
        if ($executable === null) {
            $missingIsError = ($configuration['missing'] ?? 'warning') === 'error';

            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                $missingIsError ? CheckStatus::ERROR : CheckStatus::WARN,
                $missingIsError ? Severity::ERROR : Severity::WARNING,
                strtoupper($this->tool) . ' is not installed; the configured analysis was not performed.',
                suggestion: 'Install the tool as a project development dependency and rerun CodeGuard.',
            )];
        }

        $arguments = $configuration['command'] ?? [];
        if (!is_array($arguments) || array_filter($arguments, static fn ($value): bool => !is_string($value)) !== []) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                "The checks.code_quality.{$this->tool}.command setting must be a list of strings.",
                '.codeguard.yml',
            )];
        }

        $process = new Process([$executable, ...$arguments], $context->projectPath, timeout: 300);
        $process->run();
        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput() ?: $process->getOutput());

            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                strtoupper($this->tool) . ' reported a problem (exit ' . $process->getExitCode() . '): ' . substr($output, 0, 2000),
                suggestion: 'Review the tool output and resolve findings using the project’s existing configuration.',
            )];
        }

        return [CheckResult::pass($this->getName(), $this->getGroup(), strtoupper($this->tool) . ' completed successfully.')];
    }
}
