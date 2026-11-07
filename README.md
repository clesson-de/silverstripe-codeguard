# SilverStripe CodeGuard

SilverStripe CodeGuard is a read-only PHP CLI tool for checking SilverStripe modules and applications before publication. It combines static project checks with established external tools; a clean report is not a guarantee that a project is secure.

## Requirements and installation

- PHP 8.2 or newer
- Composer 2
- Gitleaks for secret scanning (the check reports an error when the scanner is missing unless configured otherwise)

Install the package in a project:

```bash
composer require --dev clesson-de/silverstripe-codeguard
```

The package has no SilverStripe framework dependency. It supports SilverStripe 5 and 6 metadata checks and does not boot or install SilverStripe.

## Usage

```bash
vendor/bin/silverstripe-codeguard init
vendor/bin/silverstripe-codeguard check
vendor/bin/silverstripe-codeguard check --group=security
vendor/bin/silverstripe-codeguard check --group=composer
vendor/bin/silverstripe-codeguard check --type=module
vendor/bin/silverstripe-codeguard check --type=application
vendor/bin/silverstripe-codeguard check --format=json
vendor/bin/silverstripe-codeguard check --path=/path/to/project
vendor/bin/silverstripe-codeguard check --staged
vendor/bin/silverstripe-codeguard check --history
vendor/bin/silverstripe-codeguard gate
```

`check` returns exit code 1 when a check fails or errors, 0 when there are no failed checks, and 2 for invalid CLI arguments. `gate` applies `gate.fail_on` and returns 1 when a finding at a configured severity blocks release. Warnings and skipped checks remain visible; neither is reported as a pass. JSON output contains the same result statuses and locations as console output.

The default scan covers the current working tree, not the staging index or Git history. For Gitleaks, CodeGuard copies Git-tracked files and non-ignored untracked files into a private temporary snapshot; ignored untracked files such as a Git-ignored `vendor/` are excluded, but tracked files remain included even if ignored. Deleted files and symlinks are not scanned. As with other file checks, outside a Git working tree CodeGuard uses a simplified root `.gitignore` fallback and excludes `vendor/`, `node_modules/`, `coverage/`, and `.git/` directories; it cannot identify tracked files or fully reproduce Git's ignore rules. The snapshot is removed after the scan.

Gitleaks scans snapshot files using project-relative paths. Project `.gitleaks.toml` and `.gitleaksignore` settings still apply, but path-based rules and fingerprints generated from absolute paths may need adjustment. Relative paths in extended Gitleaks configurations resolve within the snapshot, so referenced files must be included in the selected file set. `check --staged` instead extracts a temporary snapshot of the Git index and checks that snapshot without changing the project. `check --history` separately requests a Gitleaks history scan. None of these scans proves the absence of all secrets.

## Configuration

Run `init` to create `.codeguard.yml`; existing configuration is never overwritten. The full starter file is also available as [`.codeguard.example.yml`](.codeguard.example.yml). If the project type cannot be detected from `silverstripe-module` package metadata or `silverstripe/recipe-*` dependencies, set it explicitly:

```yaml
project:
  type: module # or application
  supported_php: ["8.2", "8.3"]
  supported_silverstripe: ["5", "6"]

checks:
  gitleaks:
    enabled: true
    missing: error
  personal_data:
    enabled: true
    severity: warning
    allow:
      - "support@example.org"
  forbidden_files:
    enabled: true
    patterns: ["*.sql", "*.bak", "*.tmp", "*.log", ".env"]
    exclude: ["tests/fixtures/**"]
  composer:
    enabled: true
    audit: true
    outdated: false
    resolve: false
  documentation:
    enabled: true
    changelog: warning
  code_quality:
    phpstan:
      enabled: false
      missing: warning
      command: ["analyse", "--no-progress", "."]
    phpcs:
      enabled: false
      missing: warning
      command: ["."]
    phpunit:
      enabled: false
      missing: warning
      command: ["--no-coverage"]

gate:
  fail_on: ["error", "high", "critical"]
```

The file checker uses Git's tracked and non-ignored file list where Git is available, so ignored files that are already tracked are still checked. Exclusions should be narrow and documented. Never add real secrets to an allow-list. Personal-data detection is heuristic: it flags possible email addresses, phone numbers, and populated name/contact fields for human review. Exact email addresses, phone numbers, or field values may be allow-listed when legitimate.

## Checks and limitations

- **Security:** Gitleaks checks working-tree content; an optional separate history scan is available. Composer audit checks known advisories when `composer.lock` exists. Personal-data findings are heuristics, not comprehensive PII detection.
- **Composer:** validates JSON and package metadata and runs `composer validate`; Composer audit checks known advisories when a lock file exists. Optional `outdated` and `resolve` checks query repositories without updating files. Resolution is a `composer install --dry-run` with scripts and plugins disabled. Missing locks prevent a complete audit. Applications should commit a lock file; library packages normally do not.
- **Files and documentation:** checks configured unwanted-file patterns, README content, license presence/metadata, and optional changelog policy. Git-ignored untracked content is excluded, while tracked ignored content is included.
- **Code quality:** PHPStan, PHPCS, and PHPUnit are never installed automatically. Configure each explicitly to run. PHPUnit may execute project code, so it is disabled by default; enable it only for trusted projects.
- **SilverStripe:** checks Composer version constraints against configured supported versions and parses YAML documents, including SilverStripe metadata and multiple configuration blocks, without loading the framework. Each document is validated independently with Symfony YAML; aliases cannot cross document boundaries, and directives between documents are not supported. Error locations depend on the underlying parser and may be approximate. Framework configuration schemas, runtime security configuration, and deprecated-API use are not analyzed.

Checks can be disabled or configured independently. `project.supported_php` and `project.supported_silverstripe` document the versions expected to satisfy the declared Composer constraints; incompatible configured versions are reported. Use `check --group=code-quality` to review only configured external code-quality tools. A missing or skipped tool is not represented as a successful run.

## Local pre-commit hook

Install only when explicitly wanted:

```bash
vendor/bin/silverstripe-codeguard hook:install
```

The installer refuses to overwrite an existing hook. The hook checks a temporary snapshot of staged content. Local hooks are convenience checks only; use CI and protected-branch required checks as the enforcement boundary.

## GitHub Actions

The repository includes a reusable workflow at `.github/workflows/codeguard.yml`; the consuming repository controls its pull-request, protected-branch push, and release triggers. Pin the `uses` reference to a published CodeGuard release:

```yaml
name: CodeGuard
on:
  pull_request:
  push:
    branches: [main]
  release:
    types: [published]

permissions:
  contents: read

jobs:
  codeguard:
    uses: clesson-de/silverstripe-codeguard/.github/workflows/codeguard.yml@v0.1.0
    with:
      project-type: application
```

Replace `v0.1.0` with a release version that exists in your dependency policy. The reusable workflow has read-only repository permissions, installs a pinned Gitleaks release, runs tests, and writes a JSON report artifact. Configure required status checks and branch protection in the consuming repository; the workflow cannot enable those repository settings.

## Extending CodeGuard

Implement `QualityCheckInterface`, return `CheckResult` values with explicit status and severity, and register the check in `ApplicationFactory`. Checks receive `CheckContext` with the project root, parsed configuration, and detected project metadata. Exceptions become explicit error results rather than successful fallbacks.

## Development

```bash
composer install
composer test
composer validate --no-check-publish
```

Synthetic test fixtures use reserved `.invalid` email domains and contain no real credentials or personal data. See [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/architecture.md](docs/architecture.md).
