<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Support;

use Symfony\Component\Process\Process;

final class GitIndexSnapshot
{
    public function create(string $projectPath): string
    {
        $snapshot = sys_get_temp_dir() . '/codeguard-index-' . bin2hex(random_bytes(8));
        if (!mkdir($snapshot, 0700)) {
            throw new \RuntimeException('Unable to create a temporary directory for the staged Git index.');
        }

        $process = new Process(['git', 'checkout-index', '--all', '--prefix=' . $snapshot . '/'], $projectPath, timeout: 60);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->remove($snapshot);
            throw new \RuntimeException('Unable to create a staged-index snapshot: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $snapshot;
    }

    public function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
