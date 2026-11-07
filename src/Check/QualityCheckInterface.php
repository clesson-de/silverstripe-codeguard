<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

interface QualityCheckInterface
{
    public function getName(): string;

    public function getGroup(): CheckGroup;

    /** @return list<CheckResult> */
    public function run(CheckContext $context): array;
}
