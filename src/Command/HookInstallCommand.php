<?php

declare(strict_types=1);

namespace Clesson\SilverStripeCodeGuard\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class HookInstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hook:install')->setDescription('Install an opt-in pre-commit hook that checks staged content.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $git = new Process(['git', 'rev-parse', '--show-toplevel']);
        $git->run();
        if (!$git->isSuccessful()) {
            $output->writeln('<error>Unable to locate the Git repository root: ' . trim($git->getErrorOutput() ?: $git->getOutput()) . '</error>');

            return Command::FAILURE;
        }
        $root = $git->getOutput();
        $root = trim($root);
        $hooksDirectory = $root . '/.git/hooks';
        if (!is_dir($hooksDirectory)) {
            $output->writeln('<error>Git hooks directory was not found. Worktrees with a .git file are not supported by this installer.</error>');

            return Command::FAILURE;
        }
        $hookPath = $hooksDirectory . '/pre-commit';
        if (file_exists($hookPath) || is_link($hookPath)) {
            $output->writeln('<error>A pre-commit hook already exists; it was not overwritten.</error>');

            return Command::FAILURE;
        }

        $script = <<<'SH'
#!/bin/sh
set -eu
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"
if [ -x ./vendor/bin/silverstripe-codeguard ]; then
    exec ./vendor/bin/silverstripe-codeguard check --staged
fi
if [ -x ./bin/silverstripe-codeguard ]; then
    exec ./bin/silverstripe-codeguard check --staged
fi
exec silverstripe-codeguard check --staged
SH;
        $file = fopen($hookPath, 'x');
        if ($file === false) {
            $output->writeln('<error>A pre-commit hook already exists or could not be created; it was not overwritten.</error>');

            return Command::FAILURE;
        }
        $written = fwrite($file, $script . "\n");
        fclose($file);
        if ($written === false || !chmod($hookPath, 0755)) {
            unlink($hookPath);
            $output->writeln('<error>Unable to install the pre-commit hook.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Installed .git/hooks/pre-commit. It checks a temporary snapshot of the staged index; it does not scan commit history.</info>');

        return Command::SUCCESS;
    }
}
