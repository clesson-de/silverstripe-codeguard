<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Config\ConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class ConfigurationLoaderTest extends TestCase
{
    public function testLoadsSupportedProjectType(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-config-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/.codeguard.yml', "project:\n  type: application\n");

        try {
            self::assertSame(
                ['project' => ['type' => 'application']],
                (new ConfigurationLoader())->load($directory),
            );
        } finally {
            unlink($directory . '/.codeguard.yml');
            rmdir($directory);
        }
    }

    public function testRejectsUnsupportedProjectType(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-config-' . bin2hex(random_bytes(5));
        mkdir($directory);
        $loader = new ConfigurationLoader();

        try {
            file_put_contents($directory . '/.codeguard.yml', "project:\n  type: unknown\n");
            $this->expectException(\RuntimeException::class);
            $loader->load($directory);
        } finally {
            unlink($directory . '/.codeguard.yml');
            rmdir($directory);
        }
    }

    public function testMalformedYamlIsReported(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-config-' . bin2hex(random_bytes(5));
        mkdir($directory);
        file_put_contents($directory . '/.codeguard.yml', "project: [\n");

        try {
            $this->expectException(\RuntimeException::class);
            (new ConfigurationLoader())->load($directory);
        } finally {
            unlink($directory . '/.codeguard.yml');
            rmdir($directory);
        }
    }
}
