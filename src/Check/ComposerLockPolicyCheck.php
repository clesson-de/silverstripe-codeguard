<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;

final class ComposerLockPolicyCheck implements QualityCheckInterface
{
    public function __construct(private readonly ProjectFiles $files)
    {
    }

    public function getName(): string
    {
        return 'Composer lock-file policy';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::COMPOSER;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('composer')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Composer checks are disabled by configuration.')];
        }
        $hasLockFile = in_array('composer.lock', $this->files->list($context->projectPath), true);
        if ($context->project->type !== 'application') {
            if ($context->project->type === 'module' && $hasLockFile) {
                return [CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::WARN,
                    Severity::WARNING,
                    'composer.lock is included in a library or module project.',
                    'composer.lock',
                    suggestion: 'Libraries normally let the consuming application resolve dependencies instead of publishing a lock file.',
                )];
            }
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::SKIP,
                Severity::INFO,
                'Application lock-file policy does not apply to libraries and modules.',
            )];
        }
        if (!$hasLockFile) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::FAIL,
                Severity::ERROR,
                'composer.lock is missing for this application.',
                'composer.lock',
                suggestion: 'Resolve application dependencies and commit composer.lock so deployments and audits use reproducible versions.',
            )];
        }

        return [CheckResult::pass($this->getName(), $this->getGroup(), 'Application composer.lock is present.')];
    }
}
