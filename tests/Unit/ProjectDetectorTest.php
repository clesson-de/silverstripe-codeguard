<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Project\ProjectDetector;
use PHPUnit\Framework\TestCase;

final class ProjectDetectorTest extends TestCase
{
    public function testDetectsModuleAndApplicationFromComposerMetadata(): void
    {
        $detector = new ProjectDetector();
        $module = $detector->detect(dirname(__DIR__) . '/fixtures/module');
        $application = $detector->detect(dirname(__DIR__) . '/fixtures/application');

        self::assertSame('module', $module->type);
        self::assertSame('^5.0 || ^6.0', $module->silverstripeVersion);
        self::assertSame('application', $application->type);
    }

    public function testExplicitTypeOverridesComposerDetection(): void
    {
        $project = (new ProjectDetector())->detect(dirname(__DIR__) . '/fixtures/module', 'application');

        self::assertSame('application', $project->type);
    }

    public function testAmbiguousProjectRemainsUnknown(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-detect-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/composer.json', '{"name":"example/unknown","type":"library"}');

        try {
            self::assertNull((new ProjectDetector())->detect($directory)->type);
        } finally {
            unlink($directory . '/composer.json');
            rmdir($directory);
        }
    }
}
