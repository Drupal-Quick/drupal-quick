<?php

namespace DrupalQuick\Deploy;

/**
 * Pure logic for dq:deploy's Netlify site resolution.
 *
 * `netlify deploy` with no linked site prompts interactively to pick or
 * create one — and dq:deploy runs the CLI in a subprocess without a TTY, so
 * on a brand-new project that prompt would hang instead of asking. The
 * command therefore resolves the site itself: a linked site (the CLI's
 * .netlify/state.json, written on the first successful deploy) or a
 * NETLIFY_SITE_ID env var means deploy-as-usual; neither means this is a
 * first deploy, and the CLI gets --site-name so it creates the site
 * non-interactively. Kept free of Drush/Drupal so it is unit-testable
 * (tests/Unit/Deploy); file IO stays in the command.
 */
final class NetlifySite {

  /**
   * Extracts the linked site id from .netlify/state.json contents.
   *
   * Returns NULL for missing/invalid JSON or an absent/empty siteId — all
   * meaning "not linked".
   */
  public static function siteIdFromState(?string $stateJson): ?string {
    if ($stateJson === NULL || trim($stateJson) === '') {
      return NULL;
    }
    $data = json_decode($stateJson, TRUE);
    $id = is_array($data) ? ($data['siteId'] ?? NULL) : NULL;
    return (is_string($id) && $id !== '') ? $id : NULL;
  }

  /**
   * Builds a Netlify-safe site name from a base (usually the project dir).
   *
   * Netlify names are a global namespace (<name>.netlify.app), so a plain
   * project name is likely taken: a random suffix is appended unless the
   * caller supplies one (tests inject a fixed suffix). The name is only used
   * once — the first deploy links the created site's id via state.json, and
   * every later deploy resolves the id, never the name.
   */
  public static function generateSiteName(string $base, ?string $suffix = NULL): string {
    $slug = strtolower($base);
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = trim((string) preg_replace('/-{2,}/', '-', $slug), '-');
    if ($slug === '') {
      $slug = 'site';
    }
    $suffix ??= bin2hex(random_bytes(3));
    return "{$slug}-{$suffix}";
  }

}
