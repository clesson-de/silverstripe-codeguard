<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Report;

final class JsonReporter
{
    public function render(QualityReport $report, bool $gateMode, array $failOn): string
    {
        $results = array_map(
            static fn ($result): array => [
                'check' => $result->check,
                'group' => $result->group->value,
                'status' => $result->status->value,
                'severity' => $result->severity->value,
                'description' => $result->description,
                'file' => $result->file,
                'line' => $result->line,
                'suggestion' => $result->suggestion,
            ],
            $report->results,
        );

        return json_encode([
            'tool' => 'SilverStripe CodeGuard',
            'project' => [
                'name' => $report->projectName,
                'type' => $report->projectType,
                'path' => $report->projectPath,
            ],
            'scope' => $report->scope,
            'results' => $results,
            'summary' => $report->summary(),
            'quality_gate' => [
                'enabled' => $gateMode,
                'passed' => $gateMode ? $report->gatePassed($failOn) : null,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
