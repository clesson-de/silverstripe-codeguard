<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

final class CheckRunner
{
    public function __construct(private readonly CheckRegistry $registry)
    {
    }

    /** @return list<CheckResult> */
    public function run(CheckContext $context, ?string $group = null): array
    {
        $results = [];
        foreach ($this->registry->all() as $check) {
            if ($group !== null && $check->getGroup()->value !== $group) {
                continue;
            }

            try {
                array_push($results, ...$check->run($context));
            } catch (\Throwable $exception) {
                $results[] = CheckResult::issue(
                    $check->getName(),
                    $check->getGroup(),
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'The check could not be completed: ' . $exception->getMessage(),
                    suggestion: 'Correct the reported problem and run CodeGuard again.',
                );
            }
        }

        return $results;
    }
}
