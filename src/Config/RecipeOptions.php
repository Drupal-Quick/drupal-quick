<?php

namespace DrupalQuick\Config;

/**
 * Renders and injects per-recipe option blocks into config.dq.yml.
 *
 * dq-install writes each fetched recipe's options (its recipe.yml `input:`
 * definitions) into config.dq.yml as a COMMENTED block directly under that
 * recipe's entry — the user uncomments (strips the leading `# `) to enable.
 * Prefixing `# ` to the real lines keeps the uncommented result valid YAML at
 * the correct indent.
 *
 * Pure string/array logic; covered by tests/Unit/Config/RecipeOptionsTest.php.
 */
final class RecipeOptions {

  /**
   * Builds a recipe's option lines as they'd read when ACTIVE (uncommented),
   * under a mapping-form recipe entry (options: at 4 spaces, keys at 6).
   *
   * The value shown is the input's own default, so uncommenting without
   * editing is a harmless no-op; the description rides along as a trailing
   * YAML comment.
   *
   * @param array<string,mixed> $inputs
   *   The recipe.yml `input:` block.
   *
   * @return string[]
   */
  public static function activeLines(array $inputs): array {
    $lines = ['    options:'];
    foreach ($inputs as $name => $def) {
      $type    = $def['data_type'] ?? 'string';
      $default = $def['default']['value'] ?? NULL;
      if (is_bool($default)) {
        $value = $default ? 'true' : 'false';
      }
      elseif ($default === NULL) {
        $value = '~';
      }
      elseif (in_array($type, ['integer', 'float'], TRUE) && is_numeric($default)) {
        $value = (string) $default;
      }
      else {
        $value = '"' . $default . '"';
      }
      $line = "      {$name}: {$value}";
      $desc = trim((string) ($def['description'] ?? ''));
      if ($desc !== '') {
        $line .= "   # {$desc}";
      }
      $lines[] = $line;
    }
    return $lines;
  }

  /**
   * Injects a recipe's options as a COMMENTED block under its entry in the
   * raw config.dq.yml lines, in place.
   *
   * A bare-string entry ("- blog") is promoted to the mapping form
   * ("- name: blog") so the uncommented options attach to it. Idempotent: an
   * entry that already has options (active or commented) is left untouched,
   * preserving any user edits.
   *
   * @param string[] $lines
   *   The config file, split into lines.
   * @param string $key
   *   The recipe's config key (registry key or path).
   * @param string[] $activeLines
   *   Option lines as they'd read uncommented (see activeLines()).
   *
   * @return string[]
   */
  public static function injectCommented(array $lines, string $key, array $activeLines): array {
    // Bound the recipes: block — from `recipes:` to the next top-level key
    // (a line starting with a non-space, non-# character). Indented entries
    // and any injected `#`-comment lines stay inside the block.
    $start = NULL;
    foreach ($lines as $i => $l) {
      if (preg_match('/^recipes:\s*$/', $l)) {
        $start = $i;
        break;
      }
    }
    if ($start === NULL) {
      return $lines;
    }
    $end = count($lines);
    for ($i = $start + 1; $i < count($lines); $i++) {
      if (preg_match('/^[^\s#]/', $lines[$i])) {
        $end = $i;
        break;
      }
    }

    // Find this recipe's entry line (string or mapping form).
    $qk       = preg_quote($key, '/');
    $entryIdx = NULL;
    $isString = FALSE;
    for ($i = $start + 1; $i < $end; $i++) {
      if (preg_match('/^\s*-\s*["\']?' . $qk . '["\']?\s*$/', $lines[$i])) {
        $entryIdx = $i;
        $isString = TRUE;
        break;
      }
      if (preg_match('/^\s*-\s*name:\s*["\']?' . $qk . '["\']?\s*$/', $lines[$i])) {
        $entryIdx = $i;
        break;
      }
    }
    if ($entryIdx === NULL) {
      return $lines;
    }

    // Idempotency: bail if the entry already carries options, active or
    // commented. Scan its own following lines only (stop at the next sibling
    // entry or a blank line).
    for ($j = $entryIdx + 1; $j < $end; $j++) {
      if (trim($lines[$j]) === '' || preg_match('/^\s*-\s/', $lines[$j])) {
        break;
      }
      if (preg_match('/^\s*#?\s*options:\s*$/', $lines[$j])) {
        return $lines;
      }
    }

    if ($isString) {
      $lines[$entryIdx] = preg_replace('/-\s*["\']?' . $qk . '["\']?/', '- name: "' . $key . '"', $lines[$entryIdx], 1);
    }
    $commented = array_map(static fn (string $l): string => '# ' . $l, $activeLines);
    array_splice($lines, $entryIdx + 1, 0, $commented);
    return $lines;
  }

}
