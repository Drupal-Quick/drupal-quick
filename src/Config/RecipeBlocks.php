<?php

namespace DrupalQuick\Config;

/**
 * Builds and injects the recipe block catalog into config.dq.yml.
 *
 * After dq-install fetches recipes, each registry-managed recipe may advertise
 * placeable blocks in its composer.json extra.dq.recipe.blocks. This class
 * collects those into a commented reference catalog injected into config.dq.yml
 * so the user knows what keys are available for homepage: > blocks: (and any
 * future placement targets — sidebars, banners, etc.).
 *
 * Pure string/array logic; covered by tests/Unit/Config/RecipeBlocksTest.php.
 */
final class RecipeBlocks {

  // Marker used to detect a previously-injected catalog (enables idempotency).
  private const MARKER = '# ── Available recipe blocks';

  /**
   * Builds the full commented catalog block as an array of lines.
   *
   * @param array<string, array{label: string, plugin: string}> $blocks
   *   Flat map of "<recipe>/<key>" => ['label' => ..., 'plugin' => ...].
   *
   * @return string[]
   */
  public static function commentedCatalog(array $blocks): array {
    if (empty($blocks)) {
      return [];
    }

    $maxLen = max(array_map('strlen', array_keys($blocks)));

    $lines = [
      self::MARKER . ' ──────────────────────────────────────────────────────',
      '# Blocks advertised by your installed recipes. Use these keys in',
      '# homepage: > blocks: to compose the front page. In the future they may',
      '# also drive placement for other pages or regions (sidebars, banners, etc.).',
      '#',
    ];

    foreach ($blocks as $key => $meta) {
      $pad    = str_repeat(' ', $maxLen - strlen($key));
      $label  = $meta['label'] ?? $key;
      $plugin = $meta['plugin'] ?? '';
      $entry  = "#   {$key}{$pad}   — {$label}";
      if ($plugin !== '') {
        $entry .= "   ({$plugin})";
      }
      $lines[] = $entry;
    }

    $lines[] = '#';
    $lines[] = '# Uncomment and edit to activate. Listed order = display order.';
    $lines[] = '# Placed blocks are ordinary Drupal config, editable at /admin/structure/block.';
    $lines[] = '#';
    $lines[] = '# homepage:';
    $lines[] = '#   blocks:';

    foreach (array_keys($blocks) as $key) {
      $lines[] = "#     - \"{$key}\"";
    }

    return $lines;
  }

  /**
   * Injects or replaces the commented block catalog in the config file lines.
   *
   * - Skips entirely if homepage: is already active (user has configured it).
   * - Replaces the MARKER-based catalog from a prior run (idempotent).
   * - Replaces the template's generic commented homepage: block on first run.
   * - Inserts before `parameters:` or `static:` when no existing section exists.
   *
   * @param string[] $lines
   * @param array<string, array{label: string, plugin: string}> $blocks
   *
   * @return string[]
   */
  public static function injectCatalog(array $lines, array $blocks): array {
    if (empty($blocks)) {
      return $lines;
    }

    $catalog = self::commentedCatalog($blocks);

    // If homepage: is already active (not commented), leave the file alone.
    foreach ($lines as $line) {
      if (preg_match('/^homepage:\s*$/', $line)) {
        return $lines;
      }
    }

    $total        = count($lines);
    $sectionStart = NULL;
    $sectionEnd   = NULL;

    for ($i = 0; $i < $total; $i++) {
      $isMarker   = str_starts_with($lines[$i], self::MARKER);
      $isTemplate = (bool) preg_match('/^# homepage:\s*$/', $lines[$i]);

      if (!$isMarker && !$isTemplate) {
        continue;
      }

      // For the template form, scan backward through contiguous # lines to
      // include the prose description that precedes `# homepage:`.
      $sectionStart = $i;
      if ($isTemplate) {
        while ($sectionStart > 0 && str_starts_with($lines[$sectionStart - 1], '#')) {
          $sectionStart--;
        }
      }

      // Scan forward to the first non-comment, non-blank line.
      for ($j = $sectionStart + 1; $j < $total; $j++) {
        if (!str_starts_with($lines[$j], '#') && trim($lines[$j]) !== '') {
          $sectionEnd = $j;
          break;
        }
      }
      $sectionEnd = $sectionEnd ?? $total;
      break;
    }

    if ($sectionStart !== NULL) {
      // Replace the existing section; preserve blank-line separation from the
      // following key by appending an empty line to the catalog.
      array_splice($lines, $sectionStart, $sectionEnd - $sectionStart, array_merge($catalog, ['']));
      return $lines;
    }

    // No existing section — insert before `parameters:` or `static:`.
    foreach ($lines as $i => $line) {
      if (preg_match('/^(parameters|static):\s*/', $line)) {
        array_splice($lines, $i, 0, array_merge($catalog, ['']));
        return $lines;
      }
    }

    // Fallback: append at the end.
    return array_merge($lines, [''], $catalog);
  }

}
