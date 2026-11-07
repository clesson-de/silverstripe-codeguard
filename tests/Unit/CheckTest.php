<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Tests\Unit;

use Clesson\SilverStripeCodeGuard\Check\CheckContext;
use Clesson\SilverStripeCodeGuard\Check\CheckGroup;
use Clesson\SilverStripeCodeGuard\Check\CheckResult;
use Clesson\SilverStripeCodeGuard\Check\CheckStatus;
use Clesson\SilverStripeCodeGuard\Check\CheckRunner;
use Clesson\SilverStripeCodeGuard\Check\CheckRegistry;
use Clesson\SilverStripeCodeGuard\Check\QualityCheckInterface;
use Clesson\SilverStripeCodeGuard\Check\Severity;
use Clesson\SilverStripeCodeGuard\Project\ProjectInfo;
use Clesson\SilverStripeCodeGuard\Report\JsonReporter;
use Clesson\SilverStripeCodeGuard\Report\QualityReport;
use PHPUnit\Framework\TestCase;

final class CheckTest extends TestCase
{
    public function testSummaryAndQualityGateRespectConfiguredSeverity(): void
    {
        $report = new QualityReport('/project', 'example/project', 'module', [
            CheckResult::pass('ok', CheckGroup::FILES, 'Passed.'),
            CheckResult::issue('warning', CheckGroup::SECURITY, CheckStatus::WARN, Severity::WARNING, 'Review.'),
            CheckResult::issue('failure', CheckGroup::FILES, CheckStatus::FAIL, Severity::HIGH, 'Found a file.'),
        ]);

        self::assertSame(1, $report->summary()['pass']);
        self::assertSame(1, $report->summary()['warning']);
        self::assertFalse($report->gatePassed(['high', 'critical']));
        self::assertFalse($report->gatePassed(['warning']));
        self::assertTrue($report->gatePassed(['critical']));
        self::assertStringContainsString('"quality_gate"', (new JsonReporter())->render($report, true, ['high']));
    }

    public function testCheckExceptionsBecomeExplicitErrors(): void
    {
        $check = new class implements QualityCheckInterface {
            public function getName(): string { return 'Broken test check'; }
            public function getGroup(): CheckGroup { return CheckGroup::FILES; }
            public function run(CheckContext $context): array { throw new \RuntimeException('synthetic failure'); }
        };
        $runner = new CheckRunner(new CheckRegistry([$check]));
        $results = $runner->run(new CheckContext('/tmp', [], new ProjectInfo('module', 'example/project', null)));

        self::assertSame(CheckStatus::ERROR, $results[0]->status);
        self::assertStringContainsString('synthetic failure', $results[0]->description);
    }
}
