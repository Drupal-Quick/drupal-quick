<?php

namespace DrupalQuick\Config;

/**
 * Normalizes config.dq.yml recipe entries.
 *
 * An entry is one of:
 *   - a string (registry key or core/contrib path);
 *   - {name: <key-or-path>, options: {...}} — the options form; options map to
 *     the recipe's native inputs (recipe.yml `input:` declares them, with
 *     defaults, so options are always optional);
 *   - {package: ..., url: ..., options?: {...}} — an inline package spec.
 *
 * Pure logic shared by ScaffoldCommand and the bin/ scripts; covered by
 * tests/Unit/Config/RecipeEntryTest.php.
 */
final class RecipeEntry {

  /**
   * Normalizes an entry to [reference, options].
   *
   * The returned reference is what resolvePath()/packageFor() accept (the
   * string, or the inline spec minus options).
   */
  public static function normalize($recipe): array {
    if (!is_array($recipe)) {
      return [$recipe, []];
    }
    $options = $recipe['options'] ?? [];
    if (isset($recipe['name'])) {
      return [$recipe['name'], is_array($options) ? $options : []];
    }
    unset($recipe['options']);
    return [$recipe, is_array($options) ? $options : []];
  }

  /**
   * Returns the Composer package name for a normalized reference, or NULL for
   * a core/contrib path. Accepts a registry key (string) or an inline spec
   * (['package' => …, 'url' => …]).
   */
  public static function packageFor($ref, array $registry): ?string {
    if (is_array($ref)) {
      return $ref['package'] ?? NULL;
    }
    return $registry[$ref]['package'] ?? NULL;
  }

}
