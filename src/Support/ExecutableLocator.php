<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Support;

use Symfony\Component\Process\ExecutableFinder;

final class ExecutableLocator
{
    public function find(string $name, string $projectPath): ?string
    {
        $local = $projectPath . '/vendor/bin/' . $name;
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        return (new ExecutableFinder())->find($name);
    }
}
