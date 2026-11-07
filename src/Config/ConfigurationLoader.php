<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConfigurationLoader
{
    public function load(string $projectPath): array
    {
        $path = $projectPath . '/.codeguard.yml';
        if (!is_file($path)) {
            return [];
        }

        try {
            $configuration = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \RuntimeException('Invalid .codeguard.yml: ' . $exception->getMessage(), previous: $exception);
        }

        if (!is_array($configuration)) {
            throw new \RuntimeException('.codeguard.yml must contain a YAML mapping.');
        }
        foreach (['project', 'checks', 'gate'] as $section) {
            if (isset($configuration[$section]) && !is_array($configuration[$section])) {
                throw new \RuntimeException("The .codeguard.yml \"$section\" section must be a mapping.");
            }
        }
        foreach (($configuration['checks'] ?? []) as $name => $settings) {
            if (!is_string($name) || !is_array($settings)) {
                throw new \RuntimeException('Each checks entry must be a named configuration mapping.');
            }
            if (isset($settings['enabled']) && !is_bool($settings['enabled'])) {
                throw new \RuntimeException("checks.$name.enabled must be a boolean.");
            }
        }

        $type = $configuration['project']['type'] ?? null;
        if ($type !== null && !in_array($type, ['module', 'application'], true)) {
            throw new \RuntimeException('project.type must be either "module" or "application".');
        }
        foreach (['supported_php', 'supported_silverstripe'] as $versionsKey) {
            $versions = $configuration['project'][$versionsKey] ?? [];
            if (!is_array($versions)) {
                throw new \RuntimeException("project.$versionsKey must be a list of version strings.");
            }
            foreach ($versions as $version) {
                if (!is_string($version) || !preg_match('/^\d+(?:\.\d+){0,2}$/', $version)) {
                    throw new \RuntimeException("project.$versionsKey must contain numeric version strings.");
                }
            }
        }
        $failOn = $configuration['gate']['fail_on'] ?? ['error', 'high', 'critical'];
        if (!is_array($failOn)) {
            throw new \RuntimeException('gate.fail_on must be a list of severity names.');
        }
        foreach ($failOn as $severity) {
            if (!in_array($severity, ['info', 'warning', 'error', 'high', 'critical'], true)) {
                throw new \RuntimeException('gate.fail_on contains an unsupported severity.');
            }
        }
        if (!array_is_list($failOn)) {
            throw new \RuntimeException('gate.fail_on must be a list of severity names.');
        }

        $checks = $configuration['checks'] ?? [];
        $missingPolicy = $checks['gitleaks']['missing'] ?? 'error';
        if (!in_array($missingPolicy, ['warning', 'error'], true)) {
            throw new \RuntimeException('checks.gitleaks.missing must be warning or error.');
        }
        $personalData = $checks['personal_data'] ?? [];
        if (isset($personalData['severity'])
            && !in_array($personalData['severity'], ['warning', 'error', 'high', 'critical'], true)) {
            throw new \RuntimeException('checks.personal_data.severity must be warning, error, high, or critical.');
        }
        if (isset($personalData['allow'])) {
            $this->assertStringList($personalData['allow'], 'checks.personal_data.allow');
        }
        $forbiddenFiles = $checks['forbidden_files'] ?? [];
        foreach (['patterns', 'exclude'] as $key) {
            if (isset($forbiddenFiles[$key])) {
                $this->assertStringList($forbiddenFiles[$key], "checks.forbidden_files.$key");
            }
        }
        $composer = $checks['composer'] ?? [];
        foreach (['audit', 'outdated', 'resolve'] as $key) {
            if (isset($composer[$key]) && !is_bool($composer[$key])) {
                throw new \RuntimeException("checks.composer.$key must be a boolean.");
            }
        }
        if (isset($checks['documentation']['changelog'])
            && !in_array($checks['documentation']['changelog'], ['warning', 'error'], true)) {
            throw new \RuntimeException('checks.documentation.changelog must be warning or error.');
        }
        foreach (['phpstan', 'phpcs', 'phpunit'] as $tool) {
            $toolConfiguration = $checks['code_quality'][$tool] ?? [];
            if (!is_array($toolConfiguration)) {
                throw new \RuntimeException("checks.code_quality.$tool must be a mapping.");
            }
            if (isset($toolConfiguration['enabled']) && !is_bool($toolConfiguration['enabled'])) {
                throw new \RuntimeException("checks.code_quality.$tool.enabled must be a boolean.");
            }
            if (isset($toolConfiguration['missing'])
                && !in_array($toolConfiguration['missing'], ['warning', 'error'], true)) {
                throw new \RuntimeException("checks.code_quality.$tool.missing must be warning or error.");
            }
            if (isset($toolConfiguration['command'])) {
                $this->assertStringList($toolConfiguration['command'], "checks.code_quality.$tool.command");
            }
        }

        return $configuration;
    }

    private function assertStringList(mixed $value, string $key): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException("$key must be a list of strings.");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException("$key must contain only strings.");
            }
        }
    }
}
