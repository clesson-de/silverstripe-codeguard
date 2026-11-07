<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Check\CheckContext;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Clesson\SilverStripeCodeGuard\Check\SilverStripeCheck;
use Clesson\SilverStripeCodeGuard\Project\ProjectInfo;
use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SilverStripeYamlCheckTest extends TestCase
{
    #[DataProvider('validYamlDocuments')]
    public function testValidYamlIsAcceptedWithoutModifyingIt(string $yaml): void
    {
        $directory = $this->createProject($yaml);
        try {
            $results = $this->check($directory);

            self::assertCount(1, $results);
            self::assertSame(CheckStatus::PASS, $results[0]->status);
            self::assertSame($yaml, file_get_contents($directory . '/config.yml'));
        } finally {
            $this->removeProject($directory);
        }
    }

    public static function validYamlDocuments(): iterable
    {
        yield 'SilverStripe metadata and configuration' => [
            "---\nName: contacts-config\n---\n\nSilverStripe\\Admin\\LeftAndMain:\n  extra_requirements_css:\n    - example/module:client/admin/dist/bundle.css\n",
        ];
        yield 'configuration without metadata' => ["Example\\Config:\n  enabled: true\n"];
        yield 'multiple configuration blocks' => [
            "---\nName: first\n---\nExample\\First:\n  enabled: true\n---\nName: second\n---\nExample\\Second:\n  enabled: false\n",
        ];
        yield 'empty file' => [''];
        yield 'comments only' => ["# empty configuration\n"];
        yield 'empty documents' => ["---\n---\n# empty\n"];
        yield 'end marker and inline comment' => ["--- # first\nkey: value\n...\n--- # second\nkey: !custom value\n"];
        yield 'indented document markers in a block scalar' => ["text: |\n  ---\n  content\n---\nother: value\n"];
        yield 'byte order mark and CRLF' => ["\xEF\xBB\xBF---\r\nName: sample\r\n---\r\nkey: value\r\n"];
    }

    #[DataProvider('invalidYamlDocuments')]
    public function testInvalidYamlReportsOriginalFileAndLine(string $yaml, int $line): void
    {
        $directory = $this->createProject($yaml);
        try {
            $results = $this->check($directory);

            self::assertCount(1, $results);
            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertSame('config.yml', $results[0]->file);
            self::assertSame($line, $results[0]->line);
            self::assertStringContainsString('Invalid YAML syntax:', $results[0]->description);
            self::assertStringContainsString('line ' . $line, $results[0]->description);
            self::assertSame($yaml, file_get_contents($directory . '/config.yml'));
        } finally {
            $this->removeProject($directory);
        }
    }

    public static function invalidYamlDocuments(): iterable
    {
        yield 'invalid metadata' => ["---\nName: [broken\n---\nExample:\n  enabled: true\n", 2];
        yield 'invalid configuration' => ["---\nName: example\n---\nExample:\n\tinvalid: true\n", 5];
        yield 'invalid later block with CRLF' => ["---\r\nName: first\r\n---\r\nfirst: true\r\n---\r\nsecond:\r\n\tinvalid: true\r\n", 7];
        yield 'invalid single document' => ["Example:\n\tinvalid: true\n", 2];
        yield 'content after end marker without a start marker' => ["first: true\n...\nsecond: true\n", 2];
    }

    public function testAliasesCannotCrossDocumentBoundaries(): void
    {
        $directory = $this->createProject("first: &anchor value\n---\nsecond: *anchor\n");
        try {
            $results = $this->check($directory);

            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertSame('config.yml', $results[0]->file);
            self::assertStringContainsString('Reference "anchor" does not exist', $results[0]->description);
        } finally {
            $this->removeProject($directory);
        }
    }

    public function testInvalidUtf8IsNotReportedAsValid(): void
    {
        $directory = $this->createProject("key: \xFF\n");
        try {
            $results = $this->check($directory);

            self::assertSame(CheckStatus::FAIL, $results[0]->status);
            self::assertSame('config.yml', $results[0]->file);
            self::assertNull($results[0]->line);
            self::assertStringContainsString('UTF-8', $results[0]->description);
        } finally {
            $this->removeProject($directory);
        }
    }

    private function createProject(string $yaml): string
    {
        $directory = sys_get_temp_dir() . '/codeguard-yaml-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/composer.json', json_encode([
            'require' => ['silverstripe/framework' => '^5.0'],
            'autoload' => ['psr-4' => ['Example\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/config.yml', $yaml);

        return $directory;
    }

    private function check(string $directory): array
    {
        return (new SilverStripeCheck(new ProjectFiles()))->run(
            new CheckContext($directory, [], new ProjectInfo('module', null, null)),
        );
    }

    private function removeProject(string $directory): void
    {
        unlink($directory . '/config.yml');
        unlink($directory . '/composer.json');
        rmdir($directory);
    }
}
