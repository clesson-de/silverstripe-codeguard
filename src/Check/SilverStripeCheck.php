<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Composer\Semver\Semver;
use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class SilverStripeCheck implements QualityCheckInterface
{
    public function __construct(private readonly ProjectFiles $files)
    {
    }

    public function getName(): string
    {
        return 'SilverStripe compatibility';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::SILVERSTRIPE;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('silverstripe')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'SilverStripe checks are disabled by configuration.')];
        }
        if ($context->project->type === null) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::SKIP,
                Severity::INFO,
                'SilverStripe project type could not be detected; configure project.type to enable type-specific checks.',
                '.codeguard.yml',
                suggestion: 'Set project.type to module or application when the Composer metadata is ambiguous.',
            )];
        }

        $composerPath = $context->projectPath . '/composer.json';
        if (!is_file($composerPath)) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'composer.json is missing; SilverStripe compatibility cannot be evaluated.',
                'composer.json',
            )];
        }
        $composerContents = file_get_contents($composerPath);
        if ($composerContents === false) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'Unable to read composer.json for SilverStripe compatibility checks.',
                'composer.json',
            )];
        }
        $composer = json_decode($composerContents, true) ?? [];
        $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
        $framework = $require['silverstripe/framework'] ?? null;
        $recipe = $require['silverstripe/recipe-core'] ?? $require['silverstripe/recipe-cms'] ?? null;
        if (!is_string($recipe)) {
            foreach ($require as $package => $version) {
                if (is_string($package) && str_starts_with($package, 'silverstripe/recipe-') && is_string($version)) {
                    $recipe = $version;
                    break;
                }
            }
        }
        $constraint = is_string($framework) ? $framework : (is_string($recipe) ? $recipe : null);
        $results = [];
        if ($context->project->type === 'module' && empty($composer['autoload']['psr-4'])) {
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'No PSR-4 autoload mapping is declared for this SilverStripe module.',
                'composer.json',
                suggestion: 'Declare the module namespace and source directory under autoload.psr-4.',
            );
        }
        $supportedPhp = $context->configuration['project']['supported_php'] ?? [];
        $phpConstraint = $require['php'] ?? null;
        if (is_string($phpConstraint) && is_array($supportedPhp)) {
            foreach ($supportedPhp as $version) {
                if (is_string($version) && !Semver::satisfies($this->normalizeVersion($version), $phpConstraint)) {
                    $results[] = CheckResult::issue(
                        $this->getName(),
                        $this->getGroup(),
                        CheckStatus::FAIL,
                        Severity::ERROR,
                        "The PHP constraint \"$phpConstraint\" does not include configured supported version $version.",
                        'composer.json',
                        suggestion: 'Align project.supported_php with the Composer PHP constraint and the versions actually tested.',
                    );
                }
            }
        }
        $supportedSilverstripe = $context->configuration['project']['supported_silverstripe'] ?? [];
        if ($constraint !== null && is_array($supportedSilverstripe)) {
            foreach ($supportedSilverstripe as $version) {
                if (is_string($version) && !Semver::satisfies($this->normalizeVersion($version), $constraint)) {
                    $results[] = CheckResult::issue(
                        $this->getName(),
                        $this->getGroup(),
                        CheckStatus::FAIL,
                        Severity::ERROR,
                        "The SilverStripe constraint \"$constraint\" does not include configured supported version $version.",
                        'composer.json',
                        suggestion: 'Align project.supported_silverstripe with Composer constraints and versions actually tested.',
                    );
                }
            }
        }
        if ($constraint === null) {
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'No SilverStripe framework or application recipe version constraint was found.',
                'composer.json',
                suggestion: 'Declare the supported SilverStripe framework or recipe constraint in Composer.',
            );
        } elseif (!preg_match('/(?:^|[^\d])(?:\^|~|>=|>|=)?\s*[56](?:\.|$)/', $constraint)) {
            $results[] = CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::WARN,
                Severity::WARNING,
                'The SilverStripe constraint does not clearly target SilverStripe 5 or 6; automatic API compatibility checks were limited.',
                'composer.json',
                suggestion: 'Review framework support and declare tested major-version constraints.',
            );
        }

        foreach ($this->files->list($context->projectPath) as $path) {
            if (!preg_match('/\.(yml|yaml)$/i', $path)) {
                continue;
            }
            try {
                $this->validateYamlFile($context->projectPath . '/' . $path);
            } catch (ParseException $exception) {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::FAIL,
                    Severity::ERROR,
                    'Invalid YAML syntax: ' . $exception->getMessage(),
                    $path,
                    $exception->getParsedLine() > 0 ? $exception->getParsedLine() : null,
                    suggestion: 'Correct the YAML syntax. Framework-specific schema validation requires a complete SilverStripe installation and is not performed here.',
                );
            }
        }

        if ($results === []) {
            $results[] = CheckResult::pass(
                $this->getName(),
                $this->getGroup(),
                'SilverStripe metadata and YAML syntax checks passed; no framework installation or complete API compatibility analysis was performed.',
            );
        }

        return $results;
    }

    private function validateYamlFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ParseException('YAML file does not exist or cannot be read.', -1, null, $path);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ParseException('YAML file could not be read.', -1, null, $path);
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }
        // Symfony parses one document at a time; markers only delimit documents at column zero.
        $documents = preg_split('/(?=^---(?:[ \t]|$))/m', $contents);
        if ($documents === false) {
            throw new \RuntimeException('Unable to split YAML documents for validation.');
        }

        $lineOffset = 0;
        foreach ($documents as $document) {
            try {
                Yaml::parse($document, Yaml::PARSE_CUSTOM_TAGS);
            } catch (ParseException $exception) {
                if ($exception->getParsedLine() > 0) {
                    // Some parser errors point just past the final line of a document.
                    $lastLine = substr_count($document, "\n") + (str_ends_with($document, "\n") ? 0 : 1);
                    $exception->setParsedLine(min($exception->getParsedLine(), max(1, $lastLine)) + $lineOffset);
                }
                $exception->setParsedFile($path);
                throw $exception;
            }
            $lineOffset += substr_count($document, "\n");
        }
    }

    private function normalizeVersion(string $version): string
    {
        return implode('.', array_pad(explode('.', $version), 3, '0'));
    }
}
