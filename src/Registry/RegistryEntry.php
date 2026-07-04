<?php

namespace DrupalQuick\Registry;

/**
 * Builds recipe-registry entries from package metadata.
 *
 * Each recipe package's composer.json (extra.dq.recipe) is the single source
 * of truth for its catalog entry; its recipe.yml `input:` block is summarised
 * into a wizard-facing `options` map. Used by bin/dq-registry-build, which
 * loads this file with a plain require so it stays runnable without a
 * Composer autoloader. Covered by tests/Unit/Registry/RegistryEntryTest.php.
 */
final class RegistryEntry {

  /**
   * Builds a registry entry from decoded package metadata.
   *
   * @param array|null $composer
   *   The package's decoded composer.json.
   * @param string $url
   *   The package's repository web URL.
   * @param array|null $recipeData
   *   The package's parsed recipe.yml, when available.
   *
   * @return array{key: string, entry: array}|null
   *   The registry key + entry, or NULL if the package is not a recipe that
   *   opts into the catalog.
   */
  public static function fromComposer(?array $composer, string $url, ?array $recipeData = NULL): ?array {
    if (!$composer || ($composer['type'] ?? '') !== 'drupal-recipe') {
      return NULL;
    }
    $meta = $composer['extra']['dq']['recipe'] ?? NULL;
    if (!is_array($meta) || empty($meta['key'])) {
      return NULL;
    }
    $entry = [
      'label'   => (string) ($meta['label'] ?? $meta['key']),
      'package' => (string) ($composer['name'] ?? ''),
      'url'     => $url,
    ];
    // Placeable blocks the recipe advertises for homepage composition
    // (config.dq.yml homepage.blocks entries "<recipe-key>/<block-key>").
    // Carried verbatim: { <block-key>: { plugin, label } }.
    if (!empty($meta['blocks']) && is_array($meta['blocks'])) {
      $entry['blocks'] = $meta['blocks'];
    }
    // User-tunable options, summarised from the recipe's own recipe.yml input
    // block — lets pre-fetch consumers (dq-init --interactive) surface them.
    if ($recipeData !== NULL) {
      $options = self::optionsFromRecipe($recipeData);
      if ($options) {
        $entry['options'] = $options;
      }
    }
    return [
      'key'   => (string) $meta['key'],
      'entry' => $entry,
    ];
  }

  /**
   * Extracts the wizard-facing options summary from parsed recipe.yml data.
   *
   * Maps the recipe's native `input:` definitions to
   * { name: { type, description, default } }.
   */
  public static function optionsFromRecipe(array $recipeData): array {
    $options = [];
    foreach (($recipeData['input'] ?? []) as $name => $definition) {
      if (!is_array($definition)) {
        continue;
      }
      $options[$name] = [
        'type'        => (string) ($definition['data_type'] ?? 'string'),
        'description' => (string) ($definition['description'] ?? ''),
        'default'     => $definition['default']['value'] ?? NULL,
      ];
    }
    return $options;
  }

  /**
   * Normalises a git remote URL to an https web URL without the .git suffix.
   */
  public static function normalizeGitUrl(string $url): string {
    $url = trim($url);
    // git@github.com:Org/repo.git → https://github.com/Org/repo
    if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?$#', $url, $m)) {
      return "https://{$m[1]}/{$m[2]}";
    }
    return (string) preg_replace('#\.git$#', '', $url);
  }

}
