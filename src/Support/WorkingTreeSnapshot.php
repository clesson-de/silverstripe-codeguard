<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Support;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final class WorkingTreeSnapshot
{
    public function __construct(
        private readonly ProjectFiles $files,
        private readonly GitIndexSnapshot $cleanup,
    ) {
    }

    public function create(string $projectPath): string
    {
        $paths = $this->listFiles($projectPath);
        $snapshot = sys_get_temp_dir() . '/codeguard-worktree-' . bin2hex(random_bytes(8));
        if (!mkdir($snapshot, 0700)) {
            throw new \RuntimeException('Unable to create a temporary directory for the working-tree scan.');
        }

        try {
            foreach ($paths as $path) {
                $source = $projectPath . '/' . $path;
                if (is_link($source) || !file_exists($source) || is_dir($source)) {
                    continue;
                }
                if (!is_file($source) || !is_readable($source)) {
                    throw new \RuntimeException('Cannot read working-tree file for secret scanning: ' . $path);
                }
                $destination = $snapshot . '/' . $path;
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new \RuntimeException('Cannot create a directory in the working-tree scan snapshot.');
                }
                if (!copy($source, $destination) || !chmod($destination, 0600)) {
                    throw new \RuntimeException('Cannot copy working-tree file for secret scanning: ' . $path);
                }
            }
        } catch (\Throwable $exception) {
            $this->cleanup->remove($snapshot);
            throw $exception;
        }

        return $snapshot;
    }

    public function remove(string $snapshot): void
    {
        $this->cleanup->remove($snapshot);
    }

    /** @return list<string> */
    private function listFiles(string $projectPath): array
    {
        $probe = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $projectPath);
        try {
            $probe->run();
        } catch (ProcessStartFailedException $exception) {
            if ($this->hasGitDirectory($projectPath)) {
                throw new \RuntimeException('Git is required to safely select files in a Git working tree.', previous: $exception);
            }

            return $this->files->list($projectPath);
        }
        if (!$probe->isSuccessful() || trim($probe->getOutput()) !== 'true') {
            if ($this->hasGitDirectory($projectPath)) {
                throw new \RuntimeException('Unable to inspect the Git working tree: ' . trim($probe->getErrorOutput()));
            }

            return $this->files->list($projectPath);
        }

        $process = new Process(['git', 'ls-files', '-co', '-z', '--exclude-standard'], $projectPath);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to list Git working-tree files: ' . trim($process->getErrorOutput()));
        }

        return array_values(array_unique(array_filter(explode("\0", $process->getOutput()), static fn (string $path): bool => $path !== '')));
    }

    private function hasGitDirectory(string $projectPath): bool
    {
        $directory = $projectPath;
        do {
            if (file_exists($directory . '/.git')) {
                return true;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        } while (true);
    }
}
