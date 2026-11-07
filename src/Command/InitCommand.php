<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('init')->setDescription('Create a starter .codeguard.yml without overwriting existing configuration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = getcwd() . '/.codeguard.yml';
        if (file_exists($path) || is_link($path)) {
            $output->writeln('<error>.codeguard.yml already exists; it was not changed.</error>');

            return Command::FAILURE;
        }

        $configuration = <<<'YAML'
# Automatic detection uses composer.json. For ambiguous metadata, add:
# project:
#   type: module

checks:
  gitleaks:
    enabled: true
    missing: error
  personal_data:
    enabled: true
    severity: warning
    allow: []
  forbidden_files:
    enabled: true
    patterns: ["*.sql", "*.sql.gz", "*.sqlite", "*.sqlite3", "*.db", "*.dump", "*.bak", "*.backup", "*.old", "*.tmp", "*.temp", "*.log", "*.dmp", "*.stackdump", "*.debug", ".env", ".env.local", ".env.development", ".env.production", ".env.test", "auth.json", ".idea/**", ".vscode/**", "vendor/**", "node_modules/**", "coverage/**", "copilot-instructions.md", "copilot-plan*.md", "**/*plan*.md"]
    exclude: ["tests/fixtures/**"]
  composer:
    enabled: true
    audit: true
    outdated: false
    resolve: false
  documentation:
    enabled: true
    changelog: warning
  silverstripe:
    enabled: true
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
YAML;
        $file = fopen($path, 'x');
        if ($file === false) {
            $output->writeln('<error>Unable to write .codeguard.yml.</error>');

            return Command::FAILURE;
        }
        $written = fwrite($file, $configuration . "\n");
        fclose($file);
        if ($written === false) {
            unlink($path);
            $output->writeln('<error>Unable to write .codeguard.yml.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Created .codeguard.yml. Review project.type and enable only tools configured by your project.</info>');

        return Command::SUCCESS;
    }
}
