<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Clesson\SilverStripeCodeGuard\Support\GitleaksReportParser;
use Symfony\Component\Process\Process;

final class GitHistoryCheck implements QualityCheckInterface
{
    public function __construct(private readonly ExecutableLocator $executables)
    {
    }

    public function getName(): string
    {
        return 'Secret scanning (Git history)';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::SECURITY;
    }

    public function run(CheckContext $context): array
    {
        if (($context->configuration['runtime']['history_enabled'] ?? false) !== true) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'History scanning was not requested; file scans do not cover committed history.')];
        }
        if (!$context->isEnabled('gitleaks')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Gitleaks scanning is disabled by configuration.')];
        }

        $historyPath = $context->configuration['runtime']['history_project_path'] ?? $context->projectPath;
        if (!is_string($historyPath) || !is_dir($historyPath)) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'The Git history scan path is not a valid directory.',
            )];
        }
        $executable = $this->executables->find('gitleaks', $historyPath);
        if ($executable === null) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'Gitleaks is not installed; Git history was not scanned.',
                suggestion: 'Install Gitleaks and rerun with --history. Do not treat this as a successful history check.',
            )];
        }

        $process = new Process([$executable, 'git', '--redact=100', '--exit-code=1', '--report-format=json', '--no-banner'], $historyPath, timeout: 300);
        $process->run();
        if ($process->getExitCode() === 0) {
            return [CheckResult::pass($this->getName(), $this->getGroup(), 'Gitleaks found no secrets in the Git history.')];
        }
        if ($process->getExitCode() === 1) {
            $findings = (new GitleaksReportParser())->parse($process->getOutput(), $historyPath);
            if ($findings !== []) {
                return array_map(
                    static fn (array $finding): CheckResult => CheckResult::issue(
                        'Secret scanning (Git history)',
                        CheckGroup::SECURITY,
                        CheckStatus::FAIL,
                        Severity::CRITICAL,
                        'Gitleaks detected a potential secret in Git history' . ($finding['rule'] === null ? '' : ' (rule ' . $finding['rule'] . ').'),
                        $finding['file'],
                        $finding['line'],
                        'Rotate the exposed credential; removing it from the current tree does not erase it from history.',
                    ),
                    $findings,
                );
            }
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::CRITICAL,
                'Gitleaks detected one or more potential secrets in Git history.',
                suggestion: 'Rotate exposed credentials immediately; removing a secret from the current tree does not remove it from history.',
            )];
        }

        return [CheckResult::issue(
            $this->getName(),
            $this->getGroup(),
            CheckStatus::ERROR,
            Severity::ERROR,
            'Gitleaks could not scan Git history: ' . substr(trim($process->getErrorOutput() ?: $process->getOutput()), 0, 1000),
        )];
    }
}
