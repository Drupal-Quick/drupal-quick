<?php

namespace DrupalQuick\Drush\Commands;

use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dq:cleanup — removes drupalquick scaffolding artifacts from the project.
 *
 * Always removes the drupalquick package via Composer (the tool deletes its own
 * code). By default config.dq.yml is archived in place (commented out, secrets
 * redacted) as a reference; --purge deletes it entirely.
 */
#[AsCommand(
  name: 'dq:cleanup',
  description: 'Removes drupalquick scaffolding artifacts from the project.',
  aliases: ['dqc'],
)]
final class CleanupCommand extends Command {

  use DrupalQuickHelpers;

  protected function configure(): void {
    $this
      ->addOption('force', NULL, InputOption::VALUE_NONE, 'Skip the confirmation prompt.')
      ->addOption('purge', NULL, InputOption::VALUE_NONE, 'Delete config.dq.yml entirely instead of archiving it.')
      ->addOption('remove-everything', NULL, InputOption::VALUE_NONE, 'Synonym for --purge.')
      ->addUsage('dq:cleanup')
      ->addUsage('dq:cleanup --purge')
      ->addUsage('dq:cleanup --force --purge');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->io = new DrushStyle($input, $output);
    if (!$this->guardDdevEnvironment()) {
      return self::FAILURE;
    }
    $purge = $input->getOption('purge') || $input->getOption('remove-everything');

    if (!$input->getOption('force')) {
      $action = $purge
        ? 'delete config.dq.yml and remove the drupalquick package'
        : 'archive config.dq.yml (commented, kept as reference) and remove the drupalquick package';
      if (!$this->io->confirm("This will {$action}. Continue?", FALSE)) {
        $this->io->writeln('Cleanup cancelled.');
        return self::SUCCESS;
      }
    }

    $projectRoot = getcwd();
    $configFile  = "{$projectRoot}/config.dq.yml";

    if (!file_exists($configFile)) {
      $this->io->writeln('ℹ️  config.dq.yml not found — already removed.');
    }
    elseif ($purge) {
      unlink($configFile);
      $this->io->writeln('✅ Deleted config.dq.yml');
    }
    else {
      $this->archiveConfig($configFile);
      $this->io->writeln('✅ Archived config.dq.yml (commented out as a reference; safe to delete)');
    }

    $this->io->writeln('🧹 Removing drupalquick package via Composer...');
    $exitCode = $this->runProcess(['composer', 'remove', 'drupal-quick/drupal-quick']);

    if ($exitCode !== 0) {
      $this->io->error('Composer remove failed. Run `composer remove drupal-quick/drupal-quick` manually.');
      return self::FAILURE;
    }

    $this->io->writeln('✅ drupalquick removed. The project is self-contained.');
    return self::SUCCESS;
  }

  /**
   * Comments out config.dq.yml in place (redacting secrets) and prepends a
   * header so it remains an inert, human-readable reference.
   */
  private function archiveConfig(string $configFile): void {
    $header = "# Archived by drupalquick on " . date('Y-m-d') . " — reference only.\n"
      . "# This is the (commented-out) configuration used to scaffold this site.\n"
      . "# drupalquick has been removed; this file is inert and safe to delete.\n\n";
    // Redact secrets before leaving the file on disk — never archive a password.
    $contents = preg_replace('/^(\s*admin_pass:\s*).*$/m', '$1"[redacted]"', file_get_contents($configFile));
    $body     = preg_replace('/^/m', '# ', $contents);
    file_put_contents($configFile, $header . $body);
  }

}
