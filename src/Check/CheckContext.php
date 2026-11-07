<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Project\ProjectInfo;

final readonly class CheckContext
{
    public function __construct(
        public string $projectPath,
        public array $configuration,
        public ProjectInfo $project,
    ) {
    }

    public function checkConfiguration(string $key): array
    {
        $value = $this->configuration['checks'][$key] ?? [];

        return is_array($value) ? $value : [];
    }

    public function isEnabled(string $key): bool
    {
        return ($this->checkConfiguration($key)['enabled'] ?? true) !== false;
    }
}
