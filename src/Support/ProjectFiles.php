<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Support;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

final class ProjectFiles
{
    /** @return list<string> Paths relative to the project root */
    public function list(string $projectPath): array
    {
        $process = new Process(['git', 'ls-files', '-co', '-z', '--exclude-standard'], $projectPath);
        try {
            $process->run();
        } catch (ProcessStartFailedException) {
            return $this->listWithoutGit($projectPath);
        }
        if ($process->isSuccessful()) {
            $paths = array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $path): bool => $path !== ''));
            sort($paths);

            return array_values(array_unique($paths));
        }

        return $this->listWithoutGit($projectPath);
    }

    /** @return list<string> */
    private function listWithoutGit(string $projectPath): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectPath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($projectPath, '/') . '/') );
            if (preg_match('~(^|/)(\.git|vendor|node_modules|coverage)(/|$)~', $relative)) {
                continue;
            }
            if ($this->isIgnoredByGitignore($relative, $projectPath)) {
                continue;
            }
            $paths[] = $relative;
        }
        sort($paths);

        return $paths;
    }

    public function matches(string $path, string $pattern): bool
    {
        $pattern = ltrim($pattern, '/');
        if (str_contains($pattern, '**')) {
            return preg_match($this->globRegex($pattern), $path) === 1;
        }
        if (str_contains($pattern, '/')) {
            return fnmatch($pattern, $path, FNM_PATHNAME);
        }

        return fnmatch($pattern, basename($path));
    }

    private function globRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '*' && ($pattern[$index + 1] ?? '') === '*') {
                $index++;
                if (($pattern[$index + 1] ?? '') === '/') {
                    $regex .= '(?:.*/)?';
                    $index++;
                } else {
                    $regex .= '.*';
                }
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '~');
            }
        }

        return '~^' . $regex . '$~';
    }

    private function isIgnoredByGitignore(string $path, string $projectPath): bool
    {
        $ignoreFile = $projectPath . '/.gitignore';
        if (!is_file($ignoreFile)) {
            return false;
        }

        $ignored = false;
        foreach (file($ignoreFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $pattern = trim($line);
            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }
            $negated = str_starts_with($pattern, '!');
            if ($negated) {
                $pattern = substr($pattern, 1);
            }
            $directoryOnly = str_ends_with($pattern, '/');
            $pattern = trim($pattern, '/');
            $anchored = str_starts_with(trim($line), '/');
            $matched = false;
            $parts = explode('/', $path);
            $prefixes = [];
            for ($index = 1; $index <= count($parts); $index++) {
                $prefixes[] = implode('/', array_slice($parts, 0, $index));
            }

            if (!str_contains($pattern, '/') && !$anchored) {
                foreach ($parts as $index => $part) {
                    if (($directoryOnly && $index === count($parts) - 1 && !is_dir($projectPath . '/' . implode('/', array_slice($parts, 0, $index + 1))))
                        || !fnmatch($pattern, $part)) {
                        continue;
                    }
                    $matched = true;
                    break;
                }
            } else {
                foreach ($prefixes as $index => $prefix) {
                    $isFile = $index === count($prefixes) - 1;
                    if ($directoryOnly && $isFile) {
                        continue;
                    }
                    if (fnmatch($pattern, $prefix, FNM_PATHNAME)) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched) {
                $ignored = !$negated;
            }
        }

        return $ignored;
    }
}
