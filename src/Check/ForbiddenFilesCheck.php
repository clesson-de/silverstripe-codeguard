<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;

final class ForbiddenFilesCheck implements QualityCheckInterface
{
    private const DEFAULT_PATTERNS = [
        '*.sql',
        '*.sql.gz',
        '*.sql.zip',
        '*.sqlite',
        '*.sqlite3',
        '*.db',
        '*.dump',
        '*.bak',
        '*.backup',
        '*.old',
        '*.tmp',
        '*.temp',
        '*.log',
        '*.swp',
        '*.dmp',
        '*.stackdump',
        '.env',
        '.env.local',
        '.env.development',
        '.env.production',
        '.env.test',
        'auth.json',
        '.idea/**',
        '.vscode/**',
        'vendor/**',
        'node_modules/**',
        'coverage/**',
        'copilot-instructions.md',
        'copilot-plan*.md',
        '**/*plan*.md',
        '*.debug',
    ];

    public function __construct(private readonly ProjectFiles $files)
    {
    }

    public function getName(): string
    {
        return 'Forbidden files';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::FILES;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('forbidden_files')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Forbidden-file checks are disabled by configuration.')];
        }

        $configuration = $context->checkConfiguration('forbidden_files');
        $patterns = $configuration['patterns'] ?? self::DEFAULT_PATTERNS;
        $exclude = $configuration['exclude'] ?? [];
        if (!is_array($patterns) || !is_array($exclude)) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'Forbidden-file patterns and exclusions must be lists.',
                '.codeguard.yml',
            )];
        }

        $results = [];
        foreach ($this->files->list($context->projectPath) as $path) {
            $excluded = false;
            foreach ($exclude as $pattern) {
                if (is_string($pattern) && $this->files->matches($path, $pattern)) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (is_string($pattern) && $this->files->matches($path, $pattern)) {
                    $results[] = CheckResult::issue(
                        $this->getName(),
                        $this->getGroup(),
                        CheckStatus::FAIL,
                        Severity::HIGH,
                        'File matches a forbidden-file pattern.',
                        $path,
                        suggestion: 'Remove it from the published project or add a narrow, documented exclusion if it is a safe fixture.',
                    );
                    break;
                }
            }
        }

        return $results !== []
            ? $results
            : [CheckResult::pass($this->getName(), $this->getGroup(), 'No forbidden files were found among tracked and non-ignored project files.')];
    }
}
