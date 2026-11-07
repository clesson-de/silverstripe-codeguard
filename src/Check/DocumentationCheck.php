<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;

final class DocumentationCheck implements QualityCheckInterface
{
    public function __construct(private readonly ProjectFiles $files)
    {
    }

    public function getName(): string
    {
        return 'Documentation and license';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::DOCUMENTATION;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('documentation')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Documentation checks are disabled by configuration.')];
        }

        $paths = $this->files->list($context->projectPath);
        $results = [];
        if (!in_array('README.md', $paths, true)) {
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'README.md is missing.',
                'README.md',
                suggestion: 'Document installation, supported SilverStripe and PHP versions, configuration, usage, and licensing.',
            );
        } else {
            $readme = file_get_contents($context->projectPath . '/README.md');
            if ($readme === false) {
                return [CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'Unable to read README.md.',
                    'README.md',
                )];
            }
            foreach ([
                'project description' => '/^#\s+.+/m',
                'installation instructions' => '/install|installation|composer require/i',
                'supported PHP versions' => '/PHP\s*(?:8\.\d|requirement|version)/i',
                'supported SilverStripe versions' => '/SilverStripe\s*(?:5|6)|SilverStripe versions/i',
                'configuration instructions' => '/configuration|\.codeguard\.yml/i',
                'usage instructions' => '/usage|getting started|quick start/i',
                'license information' => '/license|licence/i',
            ] as $label => $pattern) {
                if (!preg_match($pattern, $readme)) {
                    $results[] = CheckResult::issue(
                        $this->getName(),
                        $this->getGroup(),
                        CheckStatus::WARN,
                        Severity::WARNING,
                        "README.md does not clearly include $label.",
                        'README.md',
                        suggestion: 'Add a concise section covering ' . $label . '.',
                    );
                }
            }
            if ($context->project->name !== null && !str_contains($readme, $context->project->name)) {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::WARN,
                    Severity::WARNING,
                    'README.md does not mention the Composer package name.',
                    'README.md',
                    suggestion: 'Use the exact package name from composer.json in installation instructions.',
                );
            }
        }

        $licenseFiles = array_filter($paths, static fn (string $path): bool => preg_match('~^(LICENSE|LICENCE)(\..*)?$~i', basename($path)) === 1);
        if ($licenseFiles === []) {
            $severity = $context->project->type === 'application' ? Severity::WARNING : Severity::ERROR;
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                $severity === Severity::ERROR ? CheckStatus::FAIL : CheckStatus::WARN,
                $severity,
                'No LICENSE or LICENCE file was found.',
                suggestion: 'Add a license file consistent with the license declared in composer.json.',
            );
        } else {
            $licensePath = reset($licenseFiles);
            $licenseContents = file_get_contents($context->projectPath . '/' . $licensePath);
            if ($licenseContents === false) {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'Unable to read the license file.',
                    $licensePath,
                );
                $licenseContents = '';
            }
            $licenseText = strtolower($licenseContents);
            $licenses = array_map('strtolower', $this->readComposerLicenses($context->projectPath));
            $matchesLicense = $licenses === [] || array_filter(
                $licenses,
                static fn (string $license): bool => $license === 'mit'
                    ? str_contains($licenseText, 'permission is hereby granted')
                    : str_contains($licenseText, $license),
            ) !== [];
            if (!$matchesLicense) {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::WARN,
                    Severity::WARNING,
                    'The license file may not match the license declared in composer.json.',
                    $licensePath,
                    suggestion: 'Verify the full license text and Composer license identifier agree.',
                );
            }
        }

        if (!in_array('CHANGELOG.md', $paths, true)) {
            $changelogRule = $context->checkConfiguration('documentation')['changelog'] ?? 'warning';
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                $changelogRule === 'error' ? CheckStatus::FAIL : CheckStatus::WARN,
                $changelogRule === 'error' ? Severity::ERROR : Severity::WARNING,
                'CHANGELOG.md is missing.',
                'CHANGELOG.md',
                suggestion: 'Add a changelog if releases are expected to be tracked for this project.',
            );
        }

        return $results !== []
            ? $results
            : [CheckResult::pass($this->getName(), $this->getGroup(), 'README, license, and changelog checks passed.')];
    }

    /** @return list<string> */
    private function readComposerLicenses(string $projectPath): array
    {
        $path = $projectPath . '/composer.json';
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read composer.json for license consistency checks.');
        }
        $composer = json_decode($contents, true);

        $license = $composer['license'] ?? null;
        if (is_string($license)) {
            return [$license];
        }

        return is_array($license) ? array_values(array_filter($license, 'is_string')) : [];
    }
}
