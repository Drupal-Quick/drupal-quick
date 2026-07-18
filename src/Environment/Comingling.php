<?php

namespace DrupalQuick\Environment;

/**
 * Guards against comingling the DDEV and bare-host workflows.
 *
 * DDEV runs commands inside a container with its own PHP, Node, Drush, database,
 * and paths. Running a Quick command on the *host* against a project that is
 * configured for DDEV mixes two different machines — divergent composer/npm
 * state, a database the host can't reach, wrong asset origins — and produces
 * confusing failures. Quick is DDEV-first, so when a project is a DDEV project
 * (`.ddev/config.yaml` present) but the command is executing on the host, we
 * stop with guidance rather than let it misbehave.
 *
 * Signals:
 *   - IS_DDEV_PROJECT=true is set only inside the DDEV web container → in-DDEV.
 *   - DQ_ALLOW_HOST=1 is an explicit escape hatch for the rare power user who
 *     really does have a host environment and knows what they're doing.
 *
 * Pure and env-injected so it is unit-testable (tests/Unit/Environment).
 */
final class Comingling {

  /**
   * Returns a guidance message when a host-run command should be refused, or
   * NULL when it's fine to proceed.
   *
   * @param string $projectRoot
   *   The project root (where .ddev/ and config.dq.yml live).
   * @param array $env
   *   Relevant environment: IS_DDEV_PROJECT and DQ_ALLOW_HOST (missing = '').
   */
  public static function hostInDdevProjectError(string $projectRoot, array $env): ?string {
    if (($env['DQ_ALLOW_HOST'] ?? '') === '1') {
      return NULL;
    }
    if (($env['IS_DDEV_PROJECT'] ?? '') === 'true') {
      return NULL;
    }
    if (!is_file(rtrim($projectRoot, '/') . '/.ddev/config.yaml')) {
      return NULL;
    }
    return 'This is a DDEV project, but the command is running on the host. '
      . 'Quick is DDEV-first — run it inside DDEV, e.g. `ddev drush dq:scaffold` '
      . 'or `ddev composer exec dq-init`. '
      . 'Mixing host and container tooling diverges composer/npm state and the '
      . 'host cannot reach DDEV\'s database. '
      . 'Set DQ_ALLOW_HOST=1 to bypass only if your host truly provides the DB, '
      . 'PHP, Node, and Drush this needs.';
  }

  /**
   * Reads the relevant environment from getenv() for the runtime callers.
   */
  public static function env(): array {
    return [
      'IS_DDEV_PROJECT' => (string) getenv('IS_DDEV_PROJECT'),
      'DQ_ALLOW_HOST' => (string) getenv('DQ_ALLOW_HOST'),
    ];
  }

}
