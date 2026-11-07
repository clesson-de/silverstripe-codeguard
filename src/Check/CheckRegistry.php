<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

final class CheckRegistry
{
    /** @param list<QualityCheckInterface> $checks */
    public function __construct(private readonly array $checks)
    {
    }

    /** @return list<QualityCheckInterface> */
    public function all(): array
    {
        return $this->checks;
    }
}
