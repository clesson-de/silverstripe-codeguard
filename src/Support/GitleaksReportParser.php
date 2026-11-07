<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Support;

final class GitleaksReportParser
{
    /**
     * @return list<array{file: ?string, line: ?int, rule: ?string}>
     */
    public function parse(string $output, string $projectPath): array
    {
        $report = json_decode($output, true);
        if (!is_array($report)) {
            return [];
        }

        $findings = [];
        foreach ($report as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $file = is_string($finding['File'] ?? null) ? $finding['File'] : null;
            if ($file !== null && str_starts_with($file, rtrim($projectPath, '/') . '/')) {
                $file = substr($file, strlen(rtrim($projectPath, '/') . '/'));
            }
            $line = $finding['StartLine'] ?? $finding['Line'] ?? null;
            $findings[] = [
                'file' => $file,
                'line' => is_int($line) || (is_string($line) && ctype_digit($line)) ? (int) $line : null,
                'rule' => is_string($finding['RuleID'] ?? null) ? $finding['RuleID'] : null,
            ];
        }

        return $findings;
    }
}
