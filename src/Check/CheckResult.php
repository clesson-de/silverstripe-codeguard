<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Check;

final readonly class CheckResult
{
    public function __construct(
        public string $check,
        public CheckGroup $group,
        public CheckStatus $status,
        public Severity $severity,
        public string $description,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $suggestion = null,
    ) {
    }

    public static function pass(string $check, CheckGroup $group, string $description): self
    {
        return new self($check, $group, CheckStatus::PASS, Severity::INFO, $description);
    }

    public static function issue(
        string $check,
        CheckGroup $group,
        CheckStatus $status,
        Severity $severity,
        string $description,
        ?string $file = null,
        ?int $line = null,
        ?string $suggestion = null,
    ): self {
        return new self($check, $group, $status, $severity, $description, $file, $line, $suggestion);
    }
}
