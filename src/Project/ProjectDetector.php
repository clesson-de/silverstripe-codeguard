<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Project;

final class ProjectDetector
{
    public function detect(string $projectPath, ?string $configuredType = null): ProjectInfo
    {
        $composerPath = $projectPath . '/composer.json';
        $composer = is_file($composerPath)
            ? json_decode((string) file_get_contents($composerPath), true)
            : null;
        $composer = is_array($composer) ? $composer : [];
        $require = array_merge(
            is_array($composer['require'] ?? null) ? $composer['require'] : [],
            is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
        );

        $type = in_array($configuredType, ['module', 'application'], true) ? $configuredType : null;
        if ($type === null) {
            $packageType = $composer['type'] ?? null;
            $hasSilverStripePackage = false;
            foreach (array_keys($require) as $package) {
                if (is_string($package) && str_starts_with($package, 'silverstripe/')) {
                    $hasSilverStripePackage = true;
                    break;
                }
            }
            if ($packageType === 'silverstripe-module') {
                $type = 'module';
            } elseif (isset($require['silverstripe/recipe-core']) || isset($require['silverstripe/recipe-cms'])
                || (str_starts_with((string) $packageType, 'silverstripe/recipe-'))
                || ($packageType === 'project' && $hasSilverStripePackage)) {
                $type = 'application';
            } elseif (isset($require['silverstripe/framework']) && !isset($require['silverstripe/recipe-core'])) {
                $type = 'module';
            }
        }

        $silverstripeVersion = null;
        $silverStripePackages = array_merge(
            ['silverstripe/framework', 'silverstripe/recipe-core', 'silverstripe/recipe-cms'],
            array_values(array_filter(
                array_keys($require),
                static fn ($package): bool => is_string($package) && str_starts_with($package, 'silverstripe/recipe-'),
            )),
        );
        foreach (array_unique($silverStripePackages) as $package) {
            if (isset($require[$package]) && is_string($require[$package])) {
                $silverstripeVersion = $require[$package];
                break;
            }
        }

        return new ProjectInfo(
            $type,
            is_string($composer['name'] ?? null) ? $composer['name'] : null,
            $silverstripeVersion,
        );
    }
}
