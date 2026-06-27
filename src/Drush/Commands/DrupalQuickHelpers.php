<?php

namespace DrupalQuick\Drush\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Serialization\Yaml;
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

  /**
   * Resolves static-export settings (target, uri).
   *
   * Persisted Drupal config (drupalquick.static) wins — it survives dq:cleanup,
   * which deletes config.dq.yml — falling back to the static: block in
   * config.dq.yml on the first run. Shared by dq:static and dq:deploy.
   */
  protected function staticSettings($self): array {
    $process = Drush::drush($self, 'config:get', ['drupalquick.static', '--format=json']);
    $process->run();
    if ($process->isSuccessful()) {
      $persisted = Json::decode(trim((string) $process->getOutput())) ?: [];
      if (!empty($persisted['target']) || !empty($persisted['uri'])) {
        return $persisted;
      }
    }
    $configFile = getcwd() . '/config.dq.yml';
    if (file_exists($configFile)) {
      $config = Yaml::decode(file_get_contents($configFile)) ?: [];
      return $config['static'] ?? [];
    }
    return [];
  }

  /**
   * Resolves Tome's static output directory (default 'html').
   */
  protected function staticDirectory($self): string {
    $process = Drush::drush($self, 'php:eval', ["echo \\Drupal\\Core\\Site\\Settings::get('tome_static_directory', 'html');"]);
    $process->run();
    $dir = trim((string) $process->getOutput());
    return $dir !== '' ? $dir : 'html';
  }

}
