<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Check\CheckContext;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Clesson\SilverStripeCodeGuard\Check\ComposerValidationCheck;
use Clesson\SilverStripeCodeGuard\Check\DocumentationCheck;
use Clesson\SilverStripeCodeGuard\Check\ForbiddenFilesCheck;
use Clesson\SilverStripeCodeGuard\Check\GitleaksCheck;
use Clesson\SilverStripeCodeGuard\Check\PersonalDataCheck;
use Clesson\SilverStripeCodeGuard\Check\ComposerLockPolicyCheck;
use Clesson\SilverStripeCodeGuard\Check\SilverStripeCheck;
use Clesson\SilverStripeCodeGuard\Check\CodeQualityToolCheck;
use Clesson\SilverStripeCodeGuard\Project\ProjectInfo;
use Clesson\SilverStripeCodeGuard\Support\ExecutableLocator;
use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;
use PHPUnit\Framework\TestCase;

final class CheckImplementationTest extends TestCase
{
    public function testComposerInvalidJsonFailsClearly(): void
    {
        $directory = $this->createTempProject();
        file_put_contents($directory . '/composer.json', '{invalid');
        try {
            $results = (new ComposerValidationCheck(new ExecutableLocator()))->run($this->context($directory));
            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertStringContainsString('not valid JSON', $results[0]->description);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testForbiddenPatternCanExcludeLegitimateSqlFixture(): void
    {
        $directory = $this->createTempProject();
        mkdir($directory . '/tests/fixtures', 0777, true);
        mkdir($directory . '/tests/fixtures/nested', 0777, true);
        file_put_contents($directory . '/tests/fixtures/schema.sql', 'synthetic fixture');
        file_put_contents($directory . '/tests/fixtures/nested/schema.sql', 'synthetic fixture');
        file_put_contents($directory . '/backup.sql', 'synthetic dump');
        $context = new CheckContext($directory, [
            'checks' => [
                'forbidden_files' => [
                    'patterns' => ['*.sql'],
                    'exclude' => ['tests/fixtures/**'],
                ],
            ],
        ], new ProjectInfo('module', null, null));

        try {
            $results = (new ForbiddenFilesCheck(new ProjectFiles()))->run($context);
            self::assertCount(1, $results);
            self::assertSame('backup.sql', $results[0]->file);
            self::assertSame(CheckStatus::FAIL, $results[0]->status);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testPersonalDataAllowlistSuppressesKnownSyntheticContact(): void
    {
        $directory = $this->createTempProject();
        file_put_contents($directory . '/contact.txt', "Public contact: support@example.invalid\n");
        file_put_contents($directory . '/tests.txt', "Synthetic address: person@example.invalid\n");
        file_put_contents($directory . '/fixture.json', '{"first_name":"Sample"}');
        file_put_contents($directory . '/phone.txt', "Synthetic contact: +1 202 555 0161\n");
        file_put_contents($directory . '/allowed.json', '{"phone":"+1 202 555 0143"}');
        $context = new CheckContext($directory, [
            'checks' => ['personal_data' => ['allow' => ['support@example.invalid', '+1 202 555 0143']]],
        ], new ProjectInfo('module', null, null));

        try {
            $results = (new PersonalDataCheck(new ProjectFiles()))->run($context);
            self::assertCount(3, $results);
            self::assertSame('fixture.json', $results[0]->file);
            self::assertSame('phone.txt', $results[1]->file);
            self::assertSame('tests.txt', $results[2]->file);
            self::assertSame(CheckStatus::WARN, $results[0]->status);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testMissingGitleaksErrorsByDefaultAndCanBeWarning(): void
    {
        $directory = $this->createTempProject();
        $oldPath = getenv('PATH');
        putenv('PATH=' . $directory);
        try {
            $context = new CheckContext($directory, [
            ], new ProjectInfo('module', null, null));
            $results = (new GitleaksCheck(new ExecutableLocator()))->run($context);
            self::assertSame(CheckStatus::ERROR, $results[0]->status);
            self::assertStringContainsString('not installed', $results[0]->description);

            $warningContext = new CheckContext($directory, [
                'checks' => ['gitleaks' => ['missing' => 'warning']],
            ], new ProjectInfo('module', null, null));
            self::assertSame(
                CheckStatus::WARN,
                (new GitleaksCheck(new ExecutableLocator()))->run($warningContext)[0]->status,
            );
        } finally {
            putenv('PATH=' . ($oldPath === false ? '' : $oldPath));
            $this->removeDirectory($directory);
        }
    }

    public function testMissingReadmeAndLicenseAreReported(): void
    {
        $directory = $this->createTempProject();
        try {
            $results = (new DocumentationCheck(new ProjectFiles()))->run($this->context($directory));
            self::assertNotEmpty($results);
            self::assertContains('README.md is missing.', array_map(static fn ($result): string => $result->description, $results));
            self::assertContains('No LICENSE or LICENCE file was found.', array_map(static fn ($result): string => $result->description, $results));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testTrackedFileIsReportedEvenWhenIgnoredByGit(): void
    {
        $directory = $this->createTempProject();
        $this->runGit($directory, ['init', '-q']);
        file_put_contents($directory . '/.gitignore', "*.sql\n");
        file_put_contents($directory . '/snapshot.sql', 'synthetic fixture');
        $this->runGit($directory, ['add', '.gitignore']);
        $this->runGit($directory, ['add', '-f', 'snapshot.sql']);

        try {
            $results = (new ForbiddenFilesCheck(new ProjectFiles()))->run(new CheckContext(
                $directory,
                [],
                new ProjectInfo('module', null, null),
            ));
            self::assertCount(1, $results);
            self::assertSame('snapshot.sql', $results[0]->file);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testGitignoreIsUsedForUntrackedFilesOutsideARepository(): void
    {
        $directory = $this->createTempProject();
        file_put_contents($directory . '/.gitignore', "*.log\n");
        file_put_contents($directory . '/debug.log', 'synthetic output');
        file_put_contents($directory . '/readme.txt', 'synthetic text');

        try {
            $results = (new ForbiddenFilesCheck(new ProjectFiles()))->run(new CheckContext(
                $directory,
                ['checks' => ['forbidden_files' => ['patterns' => ['*.log']]]],
                new ProjectInfo('module', null, null),
            ));
            self::assertSame(CheckStatus::PASS, $results[0]->status);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testApplicationWithoutLockFileFailsComposerPolicy(): void
    {
        $directory = $this->createTempProject();
        try {
            $results = (new ComposerLockPolicyCheck(new ProjectFiles()))->run(new CheckContext(
                $directory,
                [],
                new ProjectInfo('application', null, null),
            ));
            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertStringContainsString('composer.lock is missing', $results[0]->description);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testModuleLockFileIsAWarningRatherThanAnApplicationRequirement(): void
    {
        $directory = $this->createTempProject();
        file_put_contents($directory . '/composer.lock', '{}');
        try {
            $results = (new ComposerLockPolicyCheck(new ProjectFiles()))->run(new CheckContext(
                $directory,
                [],
                new ProjectInfo('module', null, null),
            ));
            self::assertSame(CheckStatus::WARN, $results[0]->status);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testConfiguredSilverStripeCompatibilityMustFitComposerConstraints(): void
    {
        $projectPath = dirname(__DIR__) . '/fixtures/module';
        $context = new CheckContext($projectPath, [
            'project' => [
                'supported_php' => ['7.4'],
                'supported_silverstripe' => ['4'],
            ],
        ], new ProjectInfo('module', 'example/silverstripe-fixture-module', '^5.0 || ^6.0'));
        $results = (new SilverStripeCheck(new ProjectFiles()))->run($context);
        $descriptions = array_map(static fn ($result): string => $result->description, $results);

        self::assertTrue((bool) array_filter($descriptions, static fn (string $description): bool => str_contains($description, 'PHP constraint')));
        self::assertTrue((bool) array_filter($descriptions, static fn (string $description): bool => str_contains($description, 'SilverStripe constraint')));
    }

    public function testExternalScannerFindingsAreNotReportedAsPasses(): void
    {
        $directory = $this->createTempProject();
        mkdir($directory . '/vendor/bin', 0777, true);
        $scanner = $directory . '/vendor/bin/gitleaks';
        $report = json_encode([[
            'File' => $directory . '/config/test.yml',
            'StartLine' => 12,
            'RuleID' => 'synthetic-rule',
            'Secret' => 'synthetic-only-value',
        ]], JSON_THROW_ON_ERROR);
        file_put_contents($scanner, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($report) . "\nexit 1\n");
        chmod($scanner, 0755);

        try {
            $results = (new GitleaksCheck(new ExecutableLocator()))->run($this->context($directory));
            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertStringContainsString('detected a potential secret', $results[0]->description);
            self::assertSame('config/test.yml', $results[0]->file);
            self::assertSame(12, $results[0]->line);
            self::assertStringNotContainsString('synthetic-only-value', $results[0]->description);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testConfiguredCodeQualityToolUsesTheExistingExecutable(): void
    {
        $directory = $this->createTempProject();
        mkdir($directory . '/vendor/bin', 0777, true);
        $tool = $directory . '/vendor/bin/phpstan';
        file_put_contents($tool, "#!/bin/sh\nexit 1\n");
        chmod($tool, 0755);
        $context = new CheckContext($directory, [
            'checks' => [
                'code_quality' => [
                    'phpstan' => ['enabled' => true, 'command' => ['analyse']],
                ],
            ],
        ], new ProjectInfo('module', null, null));

        try {
            $results = (new CodeQualityToolCheck('phpstan', 'phpstan', new ExecutableLocator()))->run($context);
            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertStringContainsString('PHPSTAN reported a problem', $results[0]->description);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function context(string $directory): CheckContext
    {
        return new CheckContext($directory, [], new ProjectInfo('module', null, null));
    }

    private function createTempProject(): string
    {
        $directory = sys_get_temp_dir() . '/codeguard-test-' . bin2hex(random_bytes(5));
        mkdir($directory);

        return $directory;
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

    private function runGit(string $directory, array $arguments): void
    {
        $process = new \Symfony\Component\Process\Process(['git', ...$arguments], $directory);
        $process->mustRun();
    }
}
