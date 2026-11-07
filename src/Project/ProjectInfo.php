<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Project;

final readonly class ProjectInfo
{
    public function __construct(
        public ?string $type,
        public ?string $name,
        public ?string $silverstripeVersion,
    ) {
    }
}
