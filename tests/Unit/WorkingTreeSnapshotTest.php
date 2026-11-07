<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Support\GitIndexSnapshot;
use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;
use Clesson\SilverStripeCodeGuard\Support\WorkingTreeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorkingTreeSnapshotTest extends TestCase
{
    public function testSnapshotIncludesTrackedIgnoredAndUntrackedProjectFiles(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-files-test-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        $cleanup = new GitIndexSnapshot();
        $snapshot = null;
        try {
            (new Process(['git', 'init', '-q'], $directory))->mustRun();
            file_put_contents($directory . '/.gitignore', "vendor/\n");
            mkdir($directory . '/vendor');
            file_put_contents($directory . '/vendor/tracked.txt', 'synthetic tracked content');
            file_put_contents($directory . '/vendor/ignored.txt', 'synthetic ignored content');
            file_put_contents($directory . '/project.txt', 'synthetic project content');
            (new Process(['git', 'add', '-f', 'vendor/tracked.txt'], $directory))->mustRun();

            $snapshot = (new WorkingTreeSnapshot(new ProjectFiles(), $cleanup))->create($directory);

            self::assertFileExists($snapshot . '/vendor/tracked.txt');
            self::assertFileDoesNotExist($snapshot . '/vendor/ignored.txt');
            self::assertSame('synthetic project content', file_get_contents($snapshot . '/project.txt'));
        } finally {
            if ($snapshot !== null) {
                $cleanup->remove($snapshot);
            }
            $cleanup->remove($directory);
        }
    }

    public function testGitFailureDoesNotSilentlyFallBackForARepository(): void
    {
        $directory = sys_get_temp_dir() . '/codeguard-files-test-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);
        mkdir($directory . '/.git');
        mkdir($directory . '/bin');
        file_put_contents($directory . '/bin/git', "#!/bin/sh\nexit 1\n");
        chmod($directory . '/bin/git', 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $directory . '/bin');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to inspect the Git working tree');
            (new WorkingTreeSnapshot(new ProjectFiles(), new GitIndexSnapshot()))->create($directory);
        } finally {
            $oldPath === false ? putenv('PATH') : putenv('PATH=' . $oldPath);
            (new GitIndexSnapshot())->remove($directory);
        }
    }
}
