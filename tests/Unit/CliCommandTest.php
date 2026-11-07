<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CliCommandTest extends TestCase
{
    public function testCheckReturnsFailureAndJsonForForbiddenFile(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-cli-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/backup.sql', 'synthetic fixture');

        try {
            $process = $this->runCli(['check', '--path=' . $directory, '--group=files', '--format=json']);
            self::assertSame(1, $process->getExitCode());
            self::assertSame('fail', json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['results'][0]['status']);
        } finally {
            unlink($directory . '/backup.sql');
            rmdir($directory);
        }
    }

    public function testInvalidGroupReturnsUsageExitCode(): void
    {
        $process = $this->runCli(['check', '--group=not-a-group']);

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Unknown check group', $process->getOutput());
    }

    public function testSilverStripeYamlFailureReportsOriginalLineInJson(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-cli-yaml-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/composer.json', json_encode([
            'type' => 'silverstripe-module',
            'require' => ['silverstripe/framework' => '^5.0'],
            'autoload' => ['psr-4' => ['Example\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/config.yml', "---\nName: test\n---\nExample:\n\tinvalid: true\n");

        try {
            $process = $this->runCli(['check', '--path=' . $directory, '--group=silverstripe', '--format=json']);
            self::assertSame(1, $process->getExitCode());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('fail', $report['results'][0]['status']);
            self::assertSame('config.yml', $report['results'][0]['file']);
            self::assertSame(5, $report['results'][0]['line']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testGateExitCodeUsesConfiguredSeverityThreshold(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-gate-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/backup.sql', 'synthetic fixture');
        file_put_contents($directory . '/.codeguard.yml', <<<'YAML'
project:
  type: module
checks:
  gitleaks:
    enabled: false
  personal_data:
    enabled: false
  composer:
    enabled: false
  documentation:
    enabled: false
  forbidden_files:
    enabled: true
    patterns: ["*.sql"]
  silverstripe:
    enabled: false
  release:
    enabled: false
gate:
  fail_on: ["high"]
YAML);

        try {
            $process = $this->runCli(['gate', '--path=' . $directory, '--format=json']);
            self::assertSame(1, $process->getExitCode());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($report['quality_gate']['passed']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testStagedCheckReadsIndexContentInsteadOfWorkingTree(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-staged-' . bin2hex(random_bytes(5));
        mkdir($directory);
        $this->runGit($directory, ['init', '-q']);
        file_put_contents($directory . '/backup.sql', 'synthetic staged content');
        $this->runGit($directory, ['add', 'backup.sql']);
        unlink($directory . '/backup.sql');
        file_put_contents($directory . '/safe.txt', 'unstaged file should not be scanned');

        try {
            $process = $this->runCli(['check', '--path=' . $directory, '--group=files', '--format=json', '--staged']);
            self::assertSame(1, $process->getExitCode());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Git index snapshot (staged content only; commit history not included)', $report['scope']);
            self::assertSame('backup.sql', $report['results'][0]['file']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testStagedAndHistoryScansUseDistinctScopes(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-history-' . bin2hex(random_bytes(5));
        mkdir($directory);
        $this->runGit($directory, ['init', '-q']);
        file_put_contents($directory . '/composer.json', '{"name":"example/project","require":{"php":"^8.2"}}');
        mkdir($directory . '/vendor/bin', 0777, true);
        $scanner = $directory . '/vendor/bin/gitleaks';
        file_put_contents($scanner, "#!/bin/sh\nexit 0\n");
        chmod($scanner, 0755);
        $this->runGit($directory, ['add', 'composer.json', 'vendor/bin/gitleaks']);

        try {
            $process = $this->runCli(['check', '--path=' . $directory, '--group=security', '--format=json', '--staged', '--history']);
            self::assertSame(0, $process->getExitCode());
            $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringContainsString('Gitleaks scanned Git history separately', $report['scope']);
            self::assertSame('pass', $report['results'][0]['status']);
            self::assertSame('pass', $report['results'][1]['status']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testInitCreatesValidConfigurationAndDoesNotOverwriteIt(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-init-' . bin2hex(random_bytes(5));
        mkdir($directory);

        try {
            $first = $this->runCli(['init'], $directory);
            self::assertSame(0, $first->getExitCode());
            $configuration = file_get_contents($directory . '/.codeguard.yml');
            self::assertIsString($configuration);
            self::assertArrayHasKey('checks', (new \Clesson\SilverStripeCodeGuard\Config\ConfigurationLoader())->load($directory));

            $second = $this->runCli(['init'], $directory);
            self::assertSame(1, $second->getExitCode());
            self::assertSame($configuration, file_get_contents($directory . '/.codeguard.yml'));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testHookInstallationIsOptInAndDoesNotOverwriteAnExistingHook(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-hook-' . bin2hex(random_bytes(5));
        mkdir($directory);
        $this->runGit($directory, ['init', '-q']);
        try {
            $first = $this->runCli(['hook:install'], $directory);
            $hook = $directory . '/.git/hooks/pre-commit';
            self::assertSame(0, $first->getExitCode());
            self::assertTrue(is_executable($hook));
            $contents = file_get_contents($hook);
            self::assertStringContainsString('check --staged', $contents);

            $second = $this->runCli(['hook:install'], $directory);
            self::assertSame(1, $second->getExitCode());
            self::assertSame($contents, file_get_contents($hook));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function runCli(array $arguments, ?string $workingDirectory = null): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/silverstripe-codeguard', ...$arguments],
            $workingDirectory ?? dirname(__DIR__, 2),
        );
        $process->run();

        return $process;
    }

    private function runGit(string $directory, array $arguments): void
    {
        (new Process(['git', ...$arguments], $directory))->mustRun();
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

}
