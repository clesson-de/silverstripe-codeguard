<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Clesson\SilverStripeCodeGuard\Support\GitleaksReportParser;
use Symfony\Component\Process\Process;

final class GitleaksCheck implements QualityCheckInterface
{
    public function __construct(private readonly ExecutableLocator $executables)
    {
    }

    public function getName(): string
    {
        return 'Secret scanning';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::SECURITY;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('gitleaks')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Gitleaks scanning is disabled by configuration.')];
        }

        $executable = $this->executables->find('gitleaks', $context->projectPath);
        if ($executable === null) {
            $configuration = $context->checkConfiguration('gitleaks');
            $missingIsError = ($configuration['missing'] ?? 'error') === 'error';

            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                $missingIsError ? CheckStatus::ERROR : CheckStatus::WARN,
                $missingIsError ? Severity::ERROR : Severity::WARNING,
                'Gitleaks is not installed; secret scanning was not performed.',
                suggestion: 'Install Gitleaks in the CI environment. A missing scanner is not a successful security check.',
            )];
        }

        $process = new Process(
            [$executable, 'dir', '--redact=100', '--exit-code=1', '--report-format=json', '--no-banner', $context->projectPath],
            $context->projectPath,
            timeout: 180,
        );
        $process->run();
        $scanScope = $context->configuration['runtime']['scan_scope'] ?? 'current working tree';
        if ($process->getExitCode() === 0) {
            return [CheckResult::pass(
                $this->getName(),
                $this->getGroup(),
                "Gitleaks found no secrets in the $scanScope; commit history was not checked.",
            )];
        }
        if ($process->getExitCode() === 1) {
            $findings = (new GitleaksReportParser())->parse($process->getOutput(), $context->projectPath);
            if ($findings !== []) {
                return array_map(
                    static fn (array $finding): CheckResult => CheckResult::issue(
                        'Secret scanning',
                        CheckGroup::SECURITY,
                        CheckStatus::FAIL,
                        Severity::CRITICAL,
                        'Gitleaks detected a potential secret' . ($finding['rule'] === null ? '' : ' (rule ' . $finding['rule'] . ')') . " in the $scanScope.",
                        $finding['file'],
                        $finding['line'],
                        'Remove or rotate the exposed credential, then scan Git history separately.',
                    ),
                    $findings,
                );
            }
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::CRITICAL,
                "Gitleaks detected one or more potential secrets in the $scanScope.",
                suggestion: 'Inspect the redacted Gitleaks report, remove or rotate exposed credentials, and scan Git history separately.',
            )];
        }

        return [CheckResult::issue(
            $this->getName(),
            $this->getGroup(),
            CheckStatus::ERROR,
            Severity::ERROR,
            'Gitleaks could not complete its scan: ' . $this->summary($process->getErrorOutput() ?: $process->getOutput()),
            suggestion: 'Check the installed Gitleaks version and rerun the command.',
        )];
    }

    private function summary(string $output): string
    {
        return substr(trim($output), 0, 1000);
    }
}
