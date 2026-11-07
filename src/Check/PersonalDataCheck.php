<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

use Clesson\SilverStripeCodeGuard\Support\ProjectFiles;

final class PersonalDataCheck implements QualityCheckInterface
{
    public function __construct(private readonly ProjectFiles $files)
    {
    }

    public function getName(): string
    {
        return 'Potential personal data';
    }

    public function getGroup(): CheckGroup
    {
        return CheckGroup::SECURITY;
    }

    public function run(CheckContext $context): array
    {
        if (!$context->isEnabled('personal_data')) {
            return [CheckResult::issue($this->getName(), $this->getGroup(), CheckStatus::SKIP, Severity::INFO, 'Personal-data checks are disabled by configuration.')];
        }

        $configuration = $context->checkConfiguration('personal_data');
        $allow = array_values(array_filter($configuration['allow'] ?? [], 'is_string'));
        $results = [];
        $composer = [];
        $composerPath = $context->projectPath . '/composer.json';
        if (is_file($composerPath)) {
            $composerContents = file_get_contents($composerPath);
            if ($composerContents === false) {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'Unable to read composer.json to identify declared public author addresses.',
                    'composer.json',
                );
            } else {
                $composer = json_decode($composerContents, true) ?? [];
            }
        }
        $authors = is_array($composer['authors'] ?? null) ? $composer['authors'] : [];
        foreach ($authors as $author) {
            if (is_array($author) && is_string($author['email'] ?? null)) {
                $allow[] = $author['email'];
            }
        }
        $allow = array_map('strtolower', $allow);
        $severity = Severity::tryFrom((string) ($configuration['severity'] ?? 'warning'));
        if ($severity === null || !in_array($severity, [Severity::WARNING, Severity::ERROR, Severity::HIGH, Severity::CRITICAL], true)) {
            return [CheckResult::issue(
                $this->getName(),
                $this->getGroup(),
                CheckStatus::ERROR,
                Severity::ERROR,
                'checks.personal_data.severity must be warning, error, high, or critical.',
                '.codeguard.yml',
            )];
        }
        $status = $severity === Severity::WARNING ? CheckStatus::WARN : CheckStatus::FAIL;
        $textExtensions = ['txt', 'csv', 'json', 'yml', 'yaml', 'php', 'env', 'xml', 'md', 'sql'];

        foreach ($this->files->list($context->projectPath) as $path) {
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $textExtensions, true)) {
                continue;
            }
            if (is_link($context->projectPath . '/' . $path)) {
                continue;
            }
            $contents = file_get_contents($context->projectPath . '/' . $path);
            if ($contents === false) {
                $results[] = CheckResult::issue(
                    $this->getName(),
                    $this->getGroup(),
                    CheckStatus::ERROR,
                    Severity::ERROR,
                    'Unable to read this text file for personal-data scanning.',
                    $path,
                    suggestion: 'Check file permissions and rerun CodeGuard.',
                );
                continue;
            }
            if (str_contains($contents, "\0")) {
                continue;
            }
            $lines = preg_split('/\R/', $contents) ?: [];
            $csvNameColumns = [];
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv' && $lines !== []) {
                foreach (str_getcsv($lines[0], ',', '"', '') as $column => $header) {
                    if (preg_match('/^(?:first[_ -]?name|last[_ -]?name|full[_ -]?name|person[_ -]?name|contact[_ -]?name|name|phone|mobile|telephone|address)$/i', trim($header)) === 1) {
                        $csvNameColumns[] = $column;
                    }
                }
            }
            foreach ($lines as $index => $line) {
                $matches = [];
                preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $line, $emails);
                foreach ($emails[0] ?? [] as $email) {
                    if (!in_array(strtolower($email), $allow, true)
                        && !(str_contains($line, 'mailto:') && str_contains(strtolower($line), 'support'))) {
                        $matches[] = 'email';
                    }
                }
                preg_match_all('/(?<!\d)(?:\+\d{1,3}[\s().-]?)?(?:\(?\d{2,4}\)?[\s().-]){2,4}\d{2,4}(?!\d)/', $line, $phones);
                foreach ($phones[0] ?? [] as $phone) {
                    $digits = preg_replace('/\D/', '', $phone);
                    if (strlen((string) $digits) >= 10 && !in_array(strtolower($phone), $allow, true)) {
                        $matches[] = 'phone number';
                    }
                }
                if ($matches === [] && preg_match_all(
                    '/(?:first[_ -]?name|last[_ -]?name|full[_ -]?name|person[_ -]?name|contact[_ -]?name|phone|mobile|telephone|address)\s*["\']?\s*[:=]\s*(?:"([^"]+)"|\'([^\']+)\'|([^,\s}]+))/i',
                    $line,
                    $fields,
                    PREG_SET_ORDER,
                ) > 0) {
                    foreach ($fields as $field) {
                        $value = strtolower(trim($field[1] ?: $field[2] ?: $field[3]));
                        if ($value !== '' && !in_array($value, $allow, true)) {
                            $matches[] = 'personal-data field';
                            break;
                        }
                    }
                }
                if ($matches === [] && $index > 0 && $csvNameColumns !== []) {
                    $values = str_getcsv($line, ',', '"', '');
                    foreach ($csvNameColumns as $column) {
                        if (isset($values[$column]) && trim($values[$column]) !== ''
                            && !in_array(strtolower(trim($values[$column])), $allow, true)) {
                            $matches[] = 'personal-data column';
                            break;
                        }
                    }
                }
                foreach (array_unique($matches) as $kind) {
                    $results[] = CheckResult::issue(
                        $this->getName(),
                        $this->getGroup(),
                        $status,
                        $severity,
                        "A possible personal $kind was found; automated detection is incomplete and requires human review.",
                        $path,
                        $index + 1,
                        'Remove personal test data or add a narrowly scoped checks.personal_data.allow exception for a legitimate public contact.',
                    );
                }
            }
        }

        return $results !== []
            ? $results
            : [CheckResult::pass(
                $this->getName(),
                $this->getGroup(),
                'No unallowlisted email addresses, phone numbers, or populated personal-data fields were detected in supported text files; this does not prove the project contains no personal data.',
            )];
    }
}
