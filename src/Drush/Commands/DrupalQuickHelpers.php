<?php

namespace DrupalQuick\Drush\Commands;

use Drush\Drush;
use Drush\Style\DrushStyle;
use Symfony\Component\Process\Process;

/**
 * Shared helpers for the drupalquick native console commands.
 *
 * Each command sets $this->io at the top of execute() and then uses these
 * helpers. The trait deliberately does not end in "Command(s).php", so Drush's
 * PSR-4 command discovery ignores it.
 */
trait DrupalQuickHelpers {

  /**
   * Styled IO for the running command. Assigned at the top of execute().
   */
  protected DrushStyle $io;

  /**
   * Returns the Drupal web root (the docroot, e.g. the project's web/ dir).
   *
   * Resolved via Drush's bootstrap manager rather than \Drupal::root() because
   * the scaffold runs around a site:install when the service container is not
   * guaranteed to be initialized. getcwd() is not used: Drush is normally
   * invoked from the project root (one level above the docroot), which is where
   * config.dq.yml lives, not where themes belong.
   */
  protected function drupalRoot(): string {
    return Drush::bootstrapManager()->getRoot();
  }

  /**
   * Runs an external command and streams its output to the console.
   *
   * Uses Symfony Process with array arguments — no shell is invoked, so there
   * is no quoting/escaping to get wrong and no command-injection surface — and
   * an optional working directory in place of a `cd … &&` prefix. Returns the
   * exit code (a process that never started is reported as 1). Timeout is
   * disabled because Composer/npm builds can run long.
   */
  protected function runProcess(array $command, ?string $cwd = NULL): int {
    $process = new Process($command, $cwd, NULL, NULL, NULL);
    $process->run(function (string $type, string $buffer): void {
      $this->io->write($buffer);
    });
    return $process->getExitCode() ?? 1;
  }

}
