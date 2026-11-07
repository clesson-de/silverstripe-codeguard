<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;

final class ReleaseReadinessCheck implements QualityCheckInterface
{
    public function __construct(private readonly ProjectFiles $files)
    {
    }

    public function getName(): string
    {
        return 'Release metadata';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::RELEASE;
    }

    public function run(CheckContext $context): array
    {
        $configuration = $context->checkConfiguration('release');
        if (($configuration['enabled'] ?? true) === false) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Release checks are disabled by configuration.')];
        }
        $files = $this->files->list($context->projectPath);
        $composerPath = $context->projectPath . '/composer.json';
        $composer = [];
        if (is_file($composerPath)) {
            $contents = file_get_contents($composerPath);
            if ($contents === false) {
                return [CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'Unable to read composer.json for release metadata checks.',
                    'composer.json',
                )];
            }
            $composer = json_decode($contents, true) ?? [];
        }
        $results = [];

        foreach (['name', 'description', 'license'] as $field) {
            if (!is_string($composer[$field] ?? null) || trim($composer[$field]) === '') {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::FAIL,
                    Severity::ERROR,
                    "Release metadata is missing composer.json \"$field\".",
                    'composer.json',
                    suggestion: 'Complete Composer package metadata before publishing.',
                );
            }
        }
        if (!in_array('README.md', $files, true)) {
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'README.md is required for a release.',
                'README.md',
            );
        }
        $hasLicense = array_filter($files, static fn (string $path): bool => preg_match('~^(LICENSE|LICENCE)(\..*)?$~i', basename($path)) === 1) !== [];
        if (!$hasLicense) {
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                $context->project->type === 'application' ? CheckStatus::WARN : CheckStatus::FAIL,
                $context->project->type === 'application' ? Severity::WARNING : Severity::ERROR,
                'No license file was found for the release.',
                suggestion: 'Add a license file consistent with composer.json.',
            );
        }
        return $results !== []
            ? $results
            : [CheckResult::pass($this->getName(), $this->getGroup(), 'Release package metadata, README, and license are present.')];
    }
}
