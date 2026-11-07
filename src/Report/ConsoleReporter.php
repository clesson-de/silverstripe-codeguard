<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Report;

use Clesson\SilverStripeCodeGuard\Check\CheckResult;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleReporter
{
    public function render(QualityReport $report, OutputInterface $output, bool $gateMode = false, array $failOn = []): void
    {
        $output->writeln('<info>SilverStripe CodeGuard</info>');
        $output->writeln(sprintf('Project: %s', $report->projectName ?? '(name not declared)'));
        $output->writeln(sprintf('Type: %s', $report->projectType ?? 'unknown'));
        $output->writeln(sprintf('Scope: %s', $report->scope));
        $output->writeln('');

        foreach ($report->results as $result) {
            $output->writeln($this->formatResult($result));
        }

        $summary = $report->summary();
        $output->writeln('');
        $output->writeln('Summary:');
        $output->writeln(sprintf(
            'Passed: %d | Warnings: %d | Errors: %d | Skipped: %d',
            $summary['pass'],
            $summary['warning'],
            $summary['fail'] + $summary['error'],
            $summary['skipped'],
        ));
        if ($gateMode) {
            $passed = $report->gatePassed($failOn);
            $output->writeln(sprintf('Quality Gate: %s', $passed ? '<info>PASSED</info>' : '<error>FAILED</error>'));
        } elseif ($summary['fail'] + $summary['error'] > 0) {
            $output->writeln('<error>Quality checks failed.</error>');
        }
        $output->writeln('<comment>Passing checks do not prove that a project is secure or free of personal data.</comment>');
    }

    private function formatResult(CheckResult $result): string
    {
        $label = match ($result->status) {
            CheckStatus::PASS => '<info>PASS</info>',
            CheckStatus::WARN => '<comment>WARN</comment>',
            CheckStatus::FAIL, CheckStatus::ERROR => '<error>FAIL</error>',
            CheckStatus::SKIP => '<comment>SKIP</comment>',
        };
        $location = $result->file === null ? '' : ' (' . $this->safeText($result->file) . ($result->line === null ? '' : ':' . $result->line) . ')';
        $line = sprintf('%s [%s] %s%s', $label, $result->group->value, $this->safeText($result->description), $location);
        if ($result->suggestion !== null) {
            $line .= "\n  <comment>Suggestion: " . $this->safeText($result->suggestion) . '</comment>';
        }

        return $line;
    }

    private function safeText(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';

        return OutputFormatter::escape($text);
    }
}
