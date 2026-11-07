<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Check\CheckContext;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Clesson\SilverStripeCodeGuard\Check\GitleaksCheck;
use Clesson\SilverStripeCodeGuard\Project\ProjectInfo;
use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class GitleaksScanTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if ((new ExecutableFinder())->find('gitleaks') === null) {
            self::markTestSkipped('Gitleaks is required for integration tests.');
        }
        $this->directory = sys_get_temp_dir() . '/codeguard-gitleaks-test-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/.gitleaks.toml', <<<'TOML'
title = "Synthetic test rule"
[[rules]]
id = "synthetic"
description = "Synthetic fixture"
regex = '''TEST_SECRET_[0-9]{6}'''
TOML);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    public function testWorkingTreeScansTrackedIgnoredAndUntrackedButNotIgnoredFiles(): void
    {
        $this->git(['init', '-q']);
        file_put_contents($this->directory . '/.gitignore', "vendor/\nignored.txt\n");
        mkdir($this->directory . '/vendor');
        file_put_contents($this->directory . '/vendor/dependency.txt', 'TEST_SECRET_100001');
        file_put_contents($this->directory . '/ignored.txt', 'TEST_SECRET_100002');
        file_put_contents($this->directory . '/vendor/tracked.txt', 'TEST_SECRET_100003');
        file_put_contents($this->directory . '/project.txt', 'TEST_SECRET_100004');
        $this->git(['add', '-f', 'vendor/tracked.txt']);

        $results = $this->scan();
        self::assertCount(2, $results);
        self::assertSame(['project.txt', 'vendor/tracked.txt'], $this->findingFiles($results));
        self::assertSame(CheckStatus::FAIL, $results[0]->status);
        self::assertSame(1, $results[0]->line);
    }

    public function testDirectoryWithoutGitUsesExistingIgnoreFallback(): void
    {
        file_put_contents($this->directory . '/.gitignore', "ignored.txt\n");
        file_put_contents($this->directory . '/ignored.txt', 'TEST_SECRET_200001');
        mkdir($this->directory . '/vendor');
        file_put_contents($this->directory . '/vendor/dependency.txt', 'TEST_SECRET_200002');
        file_put_contents($this->directory . '/project.txt', 'TEST_SECRET_200003');

        self::assertSame(['project.txt'], $this->findingFiles($this->scan()));
    }

    public function testScannerConfigurationErrorIsNotReportedAsSecretOrPass(): void
    {
        file_put_contents($this->directory . '/.gitleaks.toml', 'not valid TOML = [');

        $results = $this->scan();
        self::assertSame(CheckStatus::ERROR, $results[0]->status);
        self::assertStringContainsString('could not complete', $results[0]->description);
    }

    public function testPathBasedRuleUsesProjectRelativePaths(): void
    {
        file_put_contents($this->directory . '/.gitleaks.toml', <<<'TOML'
title = "Synthetic path rule"
[[rules]]
id = "synthetic"
description = "Synthetic fixture"
path = '''^project\.txt$'''
regex = '''TEST_SECRET_[0-9]{6}'''
TOML);
        file_put_contents($this->directory . '/project.txt', 'TEST_SECRET_400001');

        self::assertSame(['project.txt'], $this->findingFiles($this->scan()));
    }

    public function testMissingExtendedConfigurationIsAnError(): void
    {
        file_put_contents($this->directory . '/.gitleaks.toml', "[extend]\npath = 'missing.toml'\n");

        self::assertSame(CheckStatus::ERROR, $this->scan()[0]->status);
    }

    public function testStagedScanIncludesTrackedIgnoredContentsNotWorkingTree(): void
    {
        $this->git(['init', '-q']);
        file_put_contents($this->directory . '/.gitignore', "ignored.txt\n");
        file_put_contents($this->directory . '/ignored.txt', 'TEST_SECRET_500001');
        $this->git(['add', '-f', '.gitleaks.toml', '.gitignore', 'ignored.txt']);
        file_put_contents($this->directory . '/ignored.txt', 'clean working tree');

        $staged = $this->runCli(['check', '--group=security', '--format=json', '--staged']);
        self::assertSame(1, $staged->getExitCode());
        $report = json_decode($staged->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['ignored.txt'], $this->findingFilesFromReport($report, 'Secret scanning'));
        self::assertSame(CheckStatus::PASS, $this->scan()[0]->status);
    }

    public function testStagedAndHistoryScansStaySeparateFromWorkingTree(): void
    {
        $this->git(['init', '-q']);
        file_put_contents($this->directory . '/.gitignore', "ignored.txt\n");
        file_put_contents($this->directory . '/ignored.txt', 'TEST_SECRET_300001');
        $this->git(['add', '-f', '.gitleaks.toml', '.gitignore', 'ignored.txt']);
        $this->git(['-c', 'user.name=CodeGuard Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'Synthetic fixture']);
        file_put_contents($this->directory . '/ignored.txt', 'clean working tree');
        $this->git(['add', '-f', 'ignored.txt']);
        file_put_contents($this->directory . '/ignored.txt', 'TEST_SECRET_300002');
        file_put_contents($this->directory . '/unstaged.txt', 'TEST_SECRET_300003');

        $working = $this->runCli(['check', '--group=security', '--format=json']);
        self::assertSame(1, $working->getExitCode());
        $workingReport = json_decode($working->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['ignored.txt', 'unstaged.txt'], $this->findingFilesFromReport($workingReport, 'Secret scanning'));

        $staged = $this->runCli(['check', '--group=security', '--format=json', '--staged']);
        self::assertSame(0, $staged->getExitCode());
        $stagedReport = json_decode($staged->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pass', $stagedReport['results'][0]['status']);

        $history = $this->runCli(['check', '--group=security', '--format=json', '--staged', '--history']);
        self::assertSame(1, $history->getExitCode());
        $historyReport = json_decode($history->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pass', $historyReport['results'][0]['status']);
        self::assertSame('fail', $historyReport['results'][1]['status']);
        self::assertSame('ignored.txt', $historyReport['results'][1]['file']);
    }

    public function testHistoryScannerConfigurationErrorIsExplicit(): void
    {
        $this->git(['init', '-q']);
        file_put_contents($this->directory . '/.gitleaks.toml', 'not valid TOML = [');

        $history = $this->runCli(['check', '--group=security', '--format=json', '--history']);
        self::assertSame(1, $history->getExitCode());
        $report = json_decode($history->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('error', $report['results'][0]['status']);
        self::assertSame('error', $report['results'][1]['status']);
    }

    private function scan(): array
    {
        return (new GitleaksCheck(new ExecutableLocator()))->run(
            new CheckContext($this->directory, [], new ProjectInfo('module', null, null)),
        );
    }

    private function findingFiles(array $results): array
    {
        $files = array_map(static fn ($result) => $result->file, $results);
        sort($files);

        return $files;
    }

    private function findingFilesFromReport(array $report, string $check): array
    {
        $results = array_filter($report['results'], static fn (array $result): bool => $result['check'] === $check);
        $files = array_column($results, 'file');
        sort($files);

        return $files;
    }

    private function git(array $arguments): void
    {
        (new Process(['git', ...$arguments], $this->directory))->mustRun();
    }

    private function runCli(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/silverstripe-codeguard', ...$arguments, '--path=' . $this->directory], $this->directory);
        $process->run();

        return $process;
    }
}
