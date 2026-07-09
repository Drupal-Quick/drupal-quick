<?php

namespace DrupalQuick\Static;

/**
 * Pure logic for dq:static's post-export host rewrite.
 *
 * Tome's static cache is content-keyed, not target-URI-keyed: a page
 * rendered once under the site's live authoring host (e.g. a DDEV domain)
 * keeps being served from that cache on later exports even after
 * config.dq.yml's static.uri is set or changed, because nothing about the
 * page's own content changed. This leaks the authoring host into canonical
 * links, RSS, and JSON-LD. dq:static clears Tome's cache before every run as
 * the primary fix; this class is the belt-and-suspenders pass over the
 * output that guarantees correctness regardless of the exact mechanism —
 * cheap for the site sizes Quick targets. Kept pure/testable; file IO stays
 * in the command.
 */
final class ExportHostRewrite {

  /**
   * Extensions worth scanning: text formats that can carry an absolute URL.
   * Binary assets (images, fonts) are never touched.
   */
  public const EXTENSIONS = ['html', 'htm', 'xml', 'json', 'txt', 'webmanifest'];

  /**
   * Rewrites every occurrence of $fromHost with $toUri in $contents.
   *
   * No-op when the two already match, or when $fromHost is empty (nothing to
   * key the replacement on) — both mean there is nothing stale to fix.
   */
  public static function rewrite(string $contents, string $fromHost, string $toUri): string {
    $fromHost = rtrim($fromHost, '/');
    $toUri = rtrim($toUri, '/');
    if ($fromHost === '' || $fromHost === $toUri) {
      return $contents;
    }
    return str_replace($fromHost, $toUri, $contents);
  }

  /**
   * Whether a file (by its extension) is worth scanning for rewriting.
   */
  public static function isRewritable(string $path): bool {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, self::EXTENSIONS, TRUE);
  }

}
