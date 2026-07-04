<?php

namespace DrupalQuick\Config;

/**
 * Discovers design presets from a starterkit's package.json "dq" block.
 *
 * The "dq" block (presets + defaultPreset) is the single source of truth and
 * travels intact through generate-theme, so there is no filesystem scan; a
 * hardcoded set is the last resort if the manifest can't be read. The same
 * contract is read by scripts/preset.mjs in the starterkit — keep them in
 * step.
 *
 * Shared by ScaffoldCommand (interactive re-prompt) and bin/dq-init (wizard);
 * covered by tests/Unit/Config/PresetDiscoveryTest.php.
 */
final class PresetDiscovery {

  /**
   * Returns [string[] $names, string $default] for a starterkit directory.
   */
  public static function discover(string $starterkitDir): array {
    $names   = [];
    $default = 'minimal';

    $pkg = "{$starterkitDir}/package.json";
    if (file_exists($pkg)) {
      $meta    = json_decode((string) file_get_contents($pkg), TRUE) ?: [];
      $names   = $meta['dq']['presets'] ?? [];
      $default = $meta['dq']['defaultPreset'] ?? $default;
    }

    if (!$names) {
      $names = ['minimal', 'corporate'];
    }
    if (!in_array($default, $names, TRUE)) {
      $default = $names[0];
    }
    return [$names, $default];
  }

}
