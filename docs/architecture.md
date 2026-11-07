# Architecture and Implementation Plan

## Repository analysis

The repository is a newly initialized Git repository with no application source, Composer manifest, tests, or existing project configuration. The only existing files are local JetBrains IDE settings under `.idea/`; they are not application inputs and must not be overwritten or published.

The available runtime is PHP 8.5 with Composer 2.9.8. The package will declare PHP 8.2 as its minimum supported version.

## Component design

- **CLI (`Application`)** — Symfony Console commands for initialization, checks, the release gate, and explicit local hook installation.
- **Project (`ProjectDetector`)** — derives `module` or `application` from Composer metadata and SilverStripe dependencies, with configuration and CLI overrides taking precedence.
- **Configuration (`ConfigurationLoader`)** — merges safe built-in defaults with `.codeguard.yml`, validates supported options, and keeps project paths explicit.
- **Checks (`QualityCheckInterface`, registry, results)** — independently registered checks grouped by security, composer, documentation, files, code quality, SilverStripe, or release.
- **Execution/reporting** — one runner aggregates check results; console and JSON reporters use the same result model and preserve skipped/error states.
- **External tools** — Symfony Process invokes Composer, Gitleaks, PHPStan, PHPCS, and PHPUnit without installing tools or changing the inspected project.
- **Git-aware security** — distinguishes worktree, index, committed files, and history; the default worktree scan never claims to cover the index or history.
- **Automation and fixtures** — PHPUnit tests, reusable GitHub Actions workflow, example configuration, and documentation exercise both module and application behavior.

## Dependency choices

- `symfony/console` provides command parsing and terminal output.
- `symfony/yaml` parses configuration without adding a SilverStripe dependency.
- `symfony/process` safely executes optional external tools and Composer commands.
- PHPUnit is a development dependency; Gitleaks, PHPStan, PHPCS, and project PHPUnit binaries remain optional external tools.

## Implementation sequence

1. Establish package metadata, CLI, project detection, configuration, result/report abstractions, and read-only baseline checks.
2. Add explicit secret-scanner status, privacy-data warnings and allow-lists, and Git index/history scope.
3. Add external quality-tool orchestration, SilverStripe static checks, and severity-based release gate.
4. Add PHPUnit fixtures/tests, opt-in hook installation, reusable CI workflow, and complete public-package documentation.

Every phase is validated with the narrowest applicable automated test or static check. CodeGuard is an aid to review, not a proof that a project is safe.
