# Contributing

Contributions are welcome. Keep changes focused, preserve the PHP 8.2 minimum, and write all code, comments, documentation, and CLI output in English.

## Local development

```bash
composer install
composer test
composer validate --no-check-publish
```

Add PHPUnit coverage for behavior changes. Use only synthetic fixtures and reserved `.invalid` email domains; never add real credentials or personal data.

## Check design

- Implement `QualityCheckInterface` and use the shared `CheckResult` model.
- Return explicit warning, failure, error, and skipped states; do not turn unavailable tools into passes.
- Keep checks read-only. Do not install tools or modify the inspected project.
- Add configuration examples and document limitations for new checks.
- Respect staged-index semantics and avoid implying that a worktree scan covers Git history.

## Pull requests

Explain the user-visible behavior and test coverage. Run the tests and Composer validation before requesting review. Security-sensitive changes should state scan scope, false-positive considerations, and remediation guidance.
