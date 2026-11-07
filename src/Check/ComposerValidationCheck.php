<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Symfony\Component\Process\Process;

final class ComposerValidationCheck implements QualityCheckInterface
{
    public function __construct(private readonly ExecutableLocator $executables)
    {
    }

    public function getName(): string
    {
        return 'Composer validation';
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

        $path = $context->projectPath . '/composer.json';
        if (!is_file($path)) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'composer.json is missing.',
                'composer.json',
                suggestion: 'Add valid Composer package metadata before publishing this project.',
            )];
        }

        try {
            $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'composer.json is not valid JSON: ' . $exception->getMessage(),
                'composer.json',
                suggestion: 'Fix the JSON syntax and validate it with composer validate.',
            )];
        }

        if (!is_array($composer) || array_is_list($composer)) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'composer.json must contain a JSON object.',
                'composer.json',
            )];
        }

        $problems = [];
        foreach (['name', 'description', 'type'] as $field) {
            if (!isset($composer[$field]) || !is_string($composer[$field]) || trim($composer[$field]) === '') {
                $problems[] = "Required Composer metadata \"$field\" is missing or empty.";
            }
        }
        $license = $composer['license'] ?? null;
        if ((!is_string($license) || trim($license) === '')
            && (!is_array($license) || $license === []
                || array_filter($license, static fn ($value): bool => !is_string($value) || trim($value) === '') !== [])) {
            $problems[] = 'Required Composer license metadata is missing or invalid.';
        }
        if (isset($composer['name']) && is_string($composer['name'])
            && !preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~', $composer['name'])) {
            $problems[] = 'Composer package name must use the vendor/package format.';
        }
        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section]) && !is_array($composer[$section])) {
                $problems[] = "\"$section\" must be an object mapping package names to version constraints.";
                continue;
            }
            foreach (($composer[$section] ?? []) as $package => $constraint) {
                if (!is_string($package) || !is_string($constraint) || trim($constraint) === '') {
                    $problems[] = "Invalid dependency declaration in \"$section\".";
                    break;
                }
            }
        }
        if (!isset($composer['require']['php'])) {
            $problems[] = 'No PHP version requirement is declared.';
        }
        if (isset($composer['autoload']) && !is_array($composer['autoload'])) {
            $problems[] = 'autoload must be an object.';
        } elseif (isset($composer['autoload']['psr-4']) && !is_array($composer['autoload']['psr-4'])) {
            $problems[] = 'autoload.psr-4 must be an object.';
        }
        $psr4 = is_array($composer['autoload'] ?? null) && is_array($composer['autoload']['psr-4'] ?? null)
            ? $composer['autoload']['psr-4']
            : [];
        foreach ($psr4 as $namespace => $directory) {
            if (!is_string($namespace) || !is_string($directory) || $namespace === '' || $directory === '') {
                $problems[] = 'PSR-4 autoload entries must map namespaces to non-empty directory paths.';
                break;
            }
            if (!is_dir($context->projectPath . '/' . $directory)) {
                $problems[] = "PSR-4 directory \"$directory\" does not exist.";
            }
        }

        if ($problems !== []) {
            return array_map(
                fn (string $problem): CheckResult => CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::FAIL,
                    Severity::ERROR,
                    $problem,
                    'composer.json',
                    suggestion: 'Correct the Composer metadata and dependency declarations.',
                ),
                $problems,
            );
        }

        $composerExecutable = $this->executables->find('composer', $context->projectPath);
        if ($composerExecutable === null) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'Composer is unavailable; JSON and basic package metadata passed, but composer validate was not run.',
                'composer.json',
                suggestion: 'Install Composer in the CI environment to run the complete validation.',
            )];
        }

        $process = new Process(
            [$composerExecutable, 'validate', '--no-check-publish', '--no-interaction'],
            $context->projectPath,
            timeout: 60,
        );
        $process->run();
        if (!$process->isSuccessful()) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'composer validate reported an error: ' . trim($process->getErrorOutput() ?: $process->getOutput()),
                'composer.json',
                suggestion: 'Run composer validate locally and resolve the reported metadata or lock-file issue.',
            )];
        }

        return [CheckResult::pass($this->getName(), $this->getGroup(), 'composer.json passed metadata checks and composer validate.')];
    }
}
