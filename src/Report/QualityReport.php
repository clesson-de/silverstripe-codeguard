<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Report;

use Clesson\SilverStripeCodeGuard\Check\CheckResult;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Clesson\SilverStripeCodeGuard\Check\Severity;

final readonly class QualityReport
{
    /** @param list<CheckResult> $results */
    public function __construct(
        public string $projectPath,
        public ?string $projectName,
        public ?string $projectType,
        public array $results,
        public string $scope = 'working tree',
    ) {
    }

    public function summary(): array
    {
        $summary = array_fill_keys(['pass', 'warning', 'fail', 'error', 'skipped'], 0);
        foreach ($this->results as $result) {
            $summary[$result->status->value]++;
        }

        return $summary;
    }

    public function gatePassed(array $failOn): bool
    {
        $thresholds = array_map(
            static fn (string $severity): int => self::severityRank(Severity::tryFrom($severity) ?? Severity::INFO),
            array_filter($failOn, 'is_string'),
        );
        foreach ($this->results as $result) {
            if (!in_array($result->status, [CheckStatus::WARN, CheckStatus::FAIL, CheckStatus::ERROR], true)) {
                continue;
            }
            if (in_array(self::severityRank($result->severity), $thresholds, true)) {
                return false;
            }
        }

        return true;
    }

    private static function severityRank(Severity $severity): int
    {
        return match ($severity) {
            Severity::INFO => 0,
            Severity::WARNING => 1,
            Severity::ERROR => 2,
            Severity::HIGH => 3,
            Severity::CRITICAL => 4,
        };
    }
}
